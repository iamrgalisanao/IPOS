<?php

namespace App\Services\Dining;

use App\Exceptions\Dining\DiningDomainException;
use App\Exceptions\Dining\DiningTableAlreadyOccupiedException;
use App\Exceptions\Dining\DiningTicketIdempotencyDriftException;
use App\Exceptions\Dining\DiningTicketRevisionConflictException;
use App\Exceptions\Dining\DiningTicketTransitionException;
use App\Exceptions\Dining\DiningTicketUnavailableException;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketTable;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiningTicketService
{
    public function __construct(
        private readonly DiningTicketNumberService $numberService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function openTicket(
        DiningTable $table,
        array $data,
        User $actor,
        ?SalesMachineProfile $terminal
    ): DiningTicket {
        try {
            return $this->openTicketTransaction($table, $data, $actor, $terminal);
        } catch (UniqueConstraintViolationException $exception) {
            $replay = $this->resolveIdempotentReplay($table, $data);
            if ($replay) {
                return $replay;
            }

            throw $exception;
        }
    }

    private function openTicketTransaction(
        DiningTable $table,
        array $data,
        User $actor,
        ?SalesMachineProfile $terminal
    ): DiningTicket {
        return DB::transaction(function () use ($table, $data, $actor, $terminal) {
            /** @var DiningTable $lockedTable */
            $lockedTable = DiningTable::query()
                ->with('serviceArea')
                ->whereKey($table->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessTable($lockedTable, $actor);
            $this->assertTerminalMatchesTable($terminal, $lockedTable);

            $fingerprint = $this->requestFingerprint($lockedTable, $data);
            if ($existing = $this->findIdempotentTicket($lockedTable->tenant_id, $lockedTable->branch_id, $data['client_request_uuid'])) {
                return $this->idempotentResult($existing, $fingerprint);
            }

            if (!$lockedTable->serviceArea?->is_active) {
                throw DiningTicketUnavailableException::inactiveServiceArea();
            }

            if (!$lockedTable->is_active || $lockedTable->trashed()) {
                throw DiningTicketUnavailableException::inactiveTable();
            }

            if ($lockedTable->operational_state !== DiningTable::STATE_AVAILABLE) {
                throw DiningTicketUnavailableException::tableNotAvailable($lockedTable->operational_state);
            }

            $activeTicket = $this->activePrimaryTicketForTable($lockedTable);
            if ($activeTicket) {
                throw new DiningTableAlreadyOccupiedException($lockedTable->id, $activeTicket->id);
            }

            $ticket = DiningTicket::create([
                'branch_id' => $lockedTable->branch_id,
                'ticket_number' => $this->numberService->nextForBranch($lockedTable->tenant_id, $lockedTable->branch_id),
                'status' => DiningTicket::STATUS_OPEN,
                'guest_count' => (int) ($data['guest_count'] ?? 1),
                'subtotal_centavos' => 0,
                'discount_centavos' => 0,
                'service_charge_centavos' => 0,
                'tax_centavos' => 0,
                'grand_total_centavos' => 0,
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'terminal_id' => $terminal?->id,
                'ticket_revision' => 1,
                'reservation_id' => $data['reservation_id'] ?? null,
                'client_request_uuid' => $data['client_request_uuid'],
                'client_request_fingerprint' => $fingerprint,
                'notes' => $data['notes'] ?? null,
            ]);

            DiningTicketTable::create([
                'branch_id' => $lockedTable->branch_id,
                'dining_ticket_id' => $ticket->id,
                'dining_table_id' => $lockedTable->id,
                'role' => DiningTicketTable::ROLE_PRIMARY,
                'attached_at' => now(),
            ]);

            $ticket->load('primaryTableMapping.table');

            $this->auditLogger->log(
                'DINING_TICKET_OPENED',
                $ticket,
                null,
                $this->ticketAuditPayload($ticket),
                metadata: [
                    'schema_version' => 1,
                    'service_area_id' => $lockedTable->service_area_id,
                    'primary_table_id' => $lockedTable->id,
                    'terminal_id' => $terminal?->id,
                ],
            );

            return $ticket;
        });
    }

    public function transitionStatus(
        DiningTicket $ticket,
        string $targetStatus,
        User $actor,
        ?int $expectedRevision = null,
        array $context = [],
    ): DiningTicket {
        return DB::transaction(function () use ($ticket, $targetStatus, $actor, $expectedRevision, $context) {
            /** @var DiningTicket $lockedTicket */
            $lockedTicket = DiningTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeTransition($lockedTicket, $actor);

            if ($expectedRevision !== null && $expectedRevision !== $lockedTicket->ticket_revision) {
                throw new DiningTicketRevisionConflictException($lockedTicket->ticket_revision);
            }

            $this->assertCanTransition($lockedTicket, $targetStatus, $context);

            $before = $this->ticketAuditPayload($lockedTicket);
            $lockedTicket->status = $targetStatus;
            $lockedTicket->ticket_revision++;

            if ($targetStatus === DiningTicket::STATUS_CLOSED) {
                $lockedTicket->closed_at = now();
            }

            $lockedTicket->save();

            $this->auditLogger->log(
                'DINING_TICKET_STATUS_CHANGED',
                $lockedTicket,
                $before,
                $this->ticketAuditPayload($lockedTicket),
                metadata: ['schema_version' => 1],
            );

            return $lockedTicket->fresh(['primaryTableMapping.table']);
        });
    }

    public function assertCanTransition(DiningTicket $ticket, string $targetStatus, array $context = []): void
    {
        $allowed = [
            DiningTicket::STATUS_OPEN => [
                DiningTicket::STATUS_SETTLING,
                DiningTicket::STATUS_VOIDED,
            ],
            DiningTicket::STATUS_SETTLING => [
                DiningTicket::STATUS_CLOSED,
                DiningTicket::STATUS_OPEN,
            ],
        ];

        if (!in_array($targetStatus, $allowed[$ticket->status] ?? [], true)) {
            throw new DiningTicketTransitionException($ticket->status, $targetStatus);
        }

        if ($ticket->status === DiningTicket::STATUS_SETTLING && $targetStatus === DiningTicket::STATUS_OPEN) {
            $reason = $context['reason'] ?? null;
            if (!in_array($reason, ['checkout_cancelled', 'checkout_failed'], true) || $ticket->source_sale_id !== null) {
                throw new DiningTicketTransitionException($ticket->status, $targetStatus);
            }
        }
    }

    public function hasActivePrimaryTicket(DiningTable $table): bool
    {
        return $this->activePrimaryTicketForTable($table) !== null;
    }

    public function activePrimaryTicketForTable(DiningTable $table): ?DiningTicket
    {
        return DiningTicket::query()
            ->select('dining_tickets.*')
            ->join('dining_ticket_tables', 'dining_ticket_tables.dining_ticket_id', '=', 'dining_tickets.id')
            ->where('dining_ticket_tables.dining_table_id', $table->id)
            ->where('dining_ticket_tables.role', DiningTicketTable::ROLE_PRIMARY)
            ->whereNull('dining_ticket_tables.detached_at')
            ->whereIn('dining_tickets.status', DiningTicket::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->first();
    }

    public function requestFingerprint(DiningTable $table, array $data): string
    {
        $material = [
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'dining_table_id' => $table->id,
            'guest_count' => (int) ($data['guest_count'] ?? 1),
            'reservation_id' => $data['reservation_id'] ?? null,
            'notes' => isset($data['notes']) ? Str::squish((string) $data['notes']) : null,
        ];

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    private function resolveIdempotentReplay(DiningTable $table, array $data): ?DiningTicket
    {
        return DB::transaction(function () use ($table, $data) {
            /** @var DiningTable $scopedTable */
            $scopedTable = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
            $fingerprint = $this->requestFingerprint($scopedTable, $data);
            $existing = $this->findIdempotentTicket($scopedTable->tenant_id, $scopedTable->branch_id, $data['client_request_uuid']);

            return $existing ? $this->idempotentResult($existing, $fingerprint) : null;
        });
    }

    private function findIdempotentTicket(string $tenantId, string $branchId, string $clientRequestUuid): ?DiningTicket
    {
        return DiningTicket::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('client_request_uuid', $clientRequestUuid)
            ->lockForUpdate()
            ->first();
    }

    private function idempotentResult(DiningTicket $ticket, string $fingerprint): DiningTicket
    {
        if ($ticket->client_request_fingerprint !== $fingerprint) {
            throw new DiningTicketIdempotencyDriftException();
        }

        $ticket->setAttribute('idempotent_replay', true);

        return $ticket->load('primaryTableMapping.table');
    }

    private function authorizeTransition(DiningTicket $ticket, User $actor): void
    {
        if (!$actor->canAccessBranch($ticket->branch)) {
            abort(404);
        }
    }

    private function assertActorCanAccessTable(DiningTable $table, User $actor): void
    {
        if (!$actor->canAccessBranch($table->branch)) {
            abort(404);
        }
    }

    private function assertTerminalMatchesTable(?SalesMachineProfile $terminal, DiningTable $table): void
    {
        if (!$terminal) {
            throw new DiningDomainException('TERMINAL_CONTEXT_INVALID', 'Terminal context missing.', 403);
        }

        if ($terminal->tenant_id !== $table->tenant_id || $terminal->branch_id !== $table->branch_id) {
            throw new DiningDomainException('TERMINAL_CONTEXT_INVALID', 'Invalid terminal context.', 403);
        }
    }

    private function ticketAuditPayload(DiningTicket $ticket): array
    {
        $primary = $ticket->primaryTableMapping?->table;

        return [
            'schema_version' => 1,
            'id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'guest_count' => $ticket->guest_count,
            'ticket_revision' => $ticket->ticket_revision,
            'opened_by' => $ticket->opened_by,
            'opened_at' => optional($ticket->opened_at)->toIso8601String(),
            'closed_at' => optional($ticket->closed_at)->toIso8601String(),
            'terminal_id' => $ticket->terminal_id,
            'primary_table_id' => $primary?->id,
            'primary_table_number' => $primary?->table_number,
            'service_area_id' => $primary?->service_area_id,
        ];
    }
}
