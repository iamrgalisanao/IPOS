<?php

namespace App\Services\Dining;

use App\Exceptions\Dining\DiningDomainException;
use App\Models\DiningTicket;
use App\Models\Sale;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Services\POS\SaleCreationService;
use App\Values\Dining\DiningCheckoutSnapshot;
use App\Values\Dining\DiningTimelinePayload;
use Illuminate\Support\Facades\DB;

class DiningTicketCheckoutService
{
    public function __construct(
        private readonly SaleCreationService $saleCreationService,
        private readonly DiningOperationAuditService $operationAuditService,
        private readonly DiningTicketRevisionService $revisionService,
        private readonly DiningTicketTimelineService $timelineService,
    ) {
    }

    public function createSale(
        DiningTicket $ticket,
        User $actor,
        ?SalesMachineProfile $terminal,
        string $checkoutRequestUuid,
        int $expectedRevision,
        bool $isTrainingMode = false,
    ): array {
        return DB::transaction(function () use ($ticket, $actor, $terminal, $checkoutRequestUuid, $expectedRevision, $isTrainingMode) {
            $this->assertUuidNotUsedByAnotherTicket($ticket, $checkoutRequestUuid);

            /** @var DiningTicket $lockedTicket */
            $lockedTicket = DiningTicket::query()
                ->with(['items.product', 'childTickets', 'childSplitAllocations.sourceTicketItem', 'sourceSale'])
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTicket->checkout_request_uuid === $checkoutRequestUuid && $lockedTicket->source_sale_id) {
                return $this->saleResponse('duplicate_seen', $lockedTicket->fresh(['sourceSale']));
            }

            $this->assertPayable($lockedTicket);
            $this->revisionService->assertExpectedRevision($lockedTicket, $expectedRevision);

            $snapshot = DiningCheckoutSnapshot::fromTicket(
                $lockedTicket,
                $actor,
                $terminal,
                $checkoutRequestUuid,
                $isTrainingMode,
            );

            $this->assertSnapshotPayable($snapshot);

            $saleResult = $this->saleCreationService->createFromDiningSnapshot($snapshot);

            if ($saleResult['status'] === 'conflict') {
                throw new DiningDomainException(
                    'DINING_CHECKOUT_IDEMPOTENCY_DRIFT',
                    'This checkout request was already used with different dining ticket contents.',
                    409,
                    ['current_ticket_revision' => $lockedTicket->ticket_revision],
                );
            }

            $sale = $saleResult['sale'] ?? null;
            if (! $sale instanceof Sale) {
                throw new DiningDomainException(
                    'DINING_CHECKOUT_SALE_CREATION_FAILED',
                    'Dining checkout could not create a sale.',
                    422,
                );
            }

            if ($lockedTicket->source_sale_id && $lockedTicket->source_sale_id !== $sale->id) {
                throw new DiningDomainException(
                    'DINING_CHECKOUT_ALREADY_LINKED',
                    'This dining ticket is already linked to another sale.',
                    409,
                );
            }

            if (! $lockedTicket->source_sale_id) {
                $beforeAudit = $this->operationAuditService->payloadForTicket($lockedTicket);
                $beforeSnapshot = $this->revisionService->snapshot($lockedTicket);
                $fromStatus = $lockedTicket->status;

                $lockedTicket->forceFill([
                    'checkout_request_uuid' => $checkoutRequestUuid,
                    'source_sale_id' => $sale->id,
                    'status' => DiningTicket::STATUS_SETTLING,
                    'ticket_revision' => $lockedTicket->ticket_revision + 1,
                ])->save();

                $lockedTicket->load(['primaryTableMapping.table', 'sourceSale']);
                $metadata = [
                    'from_status' => $fromStatus,
                    'to_status' => DiningTicket::STATUS_SETTLING,
                    'source_sale_id' => $sale->id,
                    'checkout_request_uuid' => $checkoutRequestUuid,
                    'checkout_snapshot_hash' => $snapshot->materialHash(),
                ];
                $afterSnapshot = $this->revisionService->snapshot($lockedTicket, $metadata);
                $afterAudit = $this->operationAuditService->payloadForTicket($lockedTicket, $metadata);

                $this->operationAuditService->recordStatusChanged(
                    $lockedTicket,
                    $beforeAudit,
                    $afterAudit,
                    $actor,
                    $terminal,
                    'checkout_sale_created',
                    $metadata,
                );
                $this->revisionService->recordMutationVersion(
                    $lockedTicket,
                    $actor,
                    $terminal,
                    'checkout_sale_created',
                    $beforeSnapshot,
                    $afterSnapshot,
                    'checkout_sale_created',
                    $metadata,
                );
                $this->timelineService->recordStatusChanged(
                    $lockedTicket,
                    $fromStatus,
                    DiningTicket::STATUS_SETTLING,
                    $actor,
                    $terminal,
                    DiningTimelinePayload::fromTicket($lockedTicket, $metadata),
                );
            }

            return $this->saleResponse($saleResult['status'], $lockedTicket->fresh(['sourceSale']));
        });
    }

    public function finalizeSuccessfulPayment(Sale $sale, User $actor): ?array
    {
        if ($sale->status !== 'paid') {
            return null;
        }

        return DB::transaction(function () use ($sale, $actor) {
            /** @var DiningTicket|null $ticket */
            $ticket = DiningTicket::query()
                ->with(['parentTicket.childTickets.sourceSale', 'sourceSale', 'terminal'])
                ->where('source_sale_id', $sale->id)
                ->lockForUpdate()
                ->first();

            if (! $ticket) {
                return null;
            }

            if ($ticket->status !== DiningTicket::STATUS_CLOSED) {
                $this->closeTicket($ticket, $actor, $ticket->terminal, 'payment_completed', [
                    'source_sale_id' => $sale->id,
                ]);
            }

            $parentSettlement = null;
            if ($ticket->parent_ticket_id) {
                /** @var DiningTicket $parent */
                $parent = DiningTicket::query()
                    ->with(['childTickets.sourceSale', 'terminal'])
                    ->whereKey($ticket->parent_ticket_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $parentSettlement = $this->settlementPayload($parent);
                if (($parentSettlement['remaining_centavos'] ?? 0) === 0 && $parent->status !== DiningTicket::STATUS_CLOSED) {
                    $this->closeTicket($parent, $actor, $parent->terminal, 'child_bills_paid', $parentSettlement);
                    $parentSettlement = $this->settlementPayload($parent->fresh(['childTickets.sourceSale']));
                }
            }

            return [
                'dining_ticket' => $this->ticketPayload($ticket->fresh()),
                'parent_settlement' => $parentSettlement,
            ];
        });
    }

    public function settlementPayload(DiningTicket $parent): ?array
    {
        $parent->loadMissing('childTickets.sourceSale');

        if ($parent->childTickets->isEmpty()) {
            return null;
        }

        $payableChildren = $parent->childTickets
            ->reject(fn (DiningTicket $child) => $child->status === DiningTicket::STATUS_VOIDED)
            ->values();
        $closedChildren = $payableChildren
            ->filter(fn (DiningTicket $child) => $child->status === DiningTicket::STATUS_CLOSED)
            ->values();
        $totalCentavos = (int) $payableChildren->sum('grand_total_centavos');
        $paidCentavos = (int) $closedChildren->sum('grand_total_centavos');

        return [
            'parent_ticket_id' => $parent->id,
            'payable_child_count' => $payableChildren->count(),
            'closed_child_count' => $closedChildren->count(),
            'paid_centavos' => $paidCentavos,
            'total_centavos' => $totalCentavos,
            'remaining_centavos' => max(0, $totalCentavos - $paidCentavos),
            'status' => $totalCentavos > 0 && $paidCentavos >= $totalCentavos
                ? 'paid'
                : ($paidCentavos > 0 ? 'partially_paid' : 'unpaid'),
        ];
    }

    private function assertUuidNotUsedByAnotherTicket(DiningTicket $ticket, string $checkoutRequestUuid): void
    {
        $exists = DiningTicket::query()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('branch_id', $ticket->branch_id)
            ->where('checkout_request_uuid', $checkoutRequestUuid)
            ->whereKeyNot($ticket->id)
            ->exists();

        if ($exists) {
            throw new DiningDomainException(
                'DINING_CHECKOUT_IDEMPOTENCY_DRIFT',
                'This checkout request was already used for another dining ticket.',
                409,
            );
        }
    }

    private function assertPayable(DiningTicket $ticket): void
    {
        if ($ticket->childTickets->isNotEmpty()) {
            throw new DiningDomainException(
                'DINING_CHECKOUT_PARENT_NOT_PAYABLE',
                'Split parent tickets cannot be checked out directly.',
                409,
            );
        }

        if ($ticket->status === DiningTicket::STATUS_CLOSED) {
            throw new DiningDomainException('DINING_CHECKOUT_ALREADY_CLOSED', 'This dining ticket is already closed.', 409);
        }

        if ($ticket->status === DiningTicket::STATUS_VOIDED) {
            throw new DiningDomainException('DINING_CHECKOUT_NOT_PAYABLE', 'Voided dining tickets cannot be checked out.', 409);
        }

        if ($ticket->source_sale_id && $ticket->checkout_request_uuid) {
            throw new DiningDomainException('DINING_CHECKOUT_ALREADY_LINKED', 'This dining ticket is already linked to a sale.', 409);
        }
    }

    private function assertSnapshotPayable(DiningCheckoutSnapshot $snapshot): void
    {
        if (empty($snapshot->items())) {
            throw new DiningDomainException('DINING_CHECKOUT_EMPTY_TICKET', 'Dining checkout requires at least one payable item.', 422);
        }

        foreach ($snapshot->items() as $index => $item) {
            if (empty($item['product_id'])) {
                throw new DiningDomainException(
                    'DINING_CHECKOUT_ITEM_PRODUCT_REQUIRED',
                    'Dining checkout requires every payable item to reference a product.',
                    422,
                    ['item_index' => $index],
                );
            }
        }
    }

    private function closeTicket(DiningTicket $ticket, User $actor, ?SalesMachineProfile $terminal, string $reason, array $metadata = []): void
    {
        $ticket->refresh();
        $fromStatus = $ticket->status;
        $beforeAudit = $this->operationAuditService->payloadForTicket($ticket);
        $beforeSnapshot = $this->revisionService->snapshot($ticket);

        $ticket->forceFill([
            'status' => DiningTicket::STATUS_CLOSED,
            'closed_at' => now(),
            'ticket_revision' => $ticket->ticket_revision + 1,
        ])->save();

        $ticket->load('primaryTableMapping.table');
        $metadata = array_merge($metadata, [
            'from_status' => $fromStatus,
            'to_status' => DiningTicket::STATUS_CLOSED,
        ]);
        $afterSnapshot = $this->revisionService->snapshot($ticket, $metadata);
        $afterAudit = $this->operationAuditService->payloadForTicket($ticket, $metadata);

        $this->operationAuditService->recordStatusChanged(
            $ticket,
            $beforeAudit,
            $afterAudit,
            $actor,
            $terminal,
            $reason,
            $metadata,
        );
        $this->revisionService->recordMutationVersion(
            $ticket,
            $actor,
            $terminal,
            'ticket_closed',
            $beforeSnapshot,
            $afterSnapshot,
            $reason,
            $metadata,
        );
        $this->timelineService->recordStatusChanged(
            $ticket,
            $fromStatus,
            DiningTicket::STATUS_CLOSED,
            $actor,
            $terminal,
            DiningTimelinePayload::fromTicket($ticket, $metadata),
        );
    }

    private function saleResponse(string $status, DiningTicket $ticket): array
    {
        $sale = $ticket->sourceSale;

        return [
            'status' => $status,
            'dining_ticket' => $this->ticketPayload($ticket),
            'sale' => $sale ? [
                'id' => $sale->id,
                'status' => $sale->status,
                'total' => (string) $sale->total,
            ] : null,
            'payment_status' => $sale?->status === 'paid' ? 'paid' : 'pending',
        ];
    }

    private function ticketPayload(DiningTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'status' => $ticket->status,
            'ticket_revision' => $ticket->ticket_revision,
            'source_sale_id' => $ticket->source_sale_id,
            'checkout_request_uuid' => $ticket->checkout_request_uuid,
            'closed_at' => optional($ticket->closed_at)->toIso8601String(),
        ];
    }
}
