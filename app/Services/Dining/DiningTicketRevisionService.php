<?php

namespace App\Services\Dining;

use App\Exceptions\Dining\DiningTicketRevisionConflictException;
use App\Models\DiningTicket;
use App\Models\DiningTicketVersion;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Values\Dining\DiningTicketSnapshot;

class DiningTicketRevisionService
{
    public function recordInitialVersion(
        DiningTicket $ticket,
        User $actor,
        ?SalesMachineProfile $terminal,
        string $operation,
        DiningTicketSnapshot $afterSnapshot,
        array $metadata = []
    ): DiningTicketVersion {
        return DiningTicketVersion::create([
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'dining_ticket_id' => $ticket->id,
            'version' => 1,
            'operation' => $operation,
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal?->id,
            'after_snapshot' => $afterSnapshot->toArray(),
            'metadata' => $this->metadata($metadata),
            'created_at' => now(),
        ]);
    }

    public function recordMutationVersion(
        DiningTicket $ticket,
        User $actor,
        ?SalesMachineProfile $terminal,
        string $operation,
        DiningTicketSnapshot $beforeSnapshot,
        DiningTicketSnapshot $afterSnapshot,
        ?string $reason = null,
        array $metadata = []
    ): DiningTicketVersion {
        return DiningTicketVersion::create([
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'dining_ticket_id' => $ticket->id,
            'version' => $ticket->ticket_revision,
            'operation' => $operation,
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal?->id,
            'reason' => $reason,
            'before_snapshot' => $beforeSnapshot->toArray(),
            'after_snapshot' => $afterSnapshot->toArray(),
            'metadata' => $this->metadata($metadata),
            'created_at' => now(),
        ]);
    }

    public function assertExpectedRevision(DiningTicket $ticket, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && $expectedRevision !== $ticket->ticket_revision) {
            throw new DiningTicketRevisionConflictException($ticket->ticket_revision);
        }
    }

    public function conflictPayload(DiningTicket $ticket): array
    {
        return [
            'code' => 'DINING_TICKET_REVISION_CONFLICT',
            'message' => 'The dining ticket was updated by another terminal.',
            'current_ticket_revision' => $ticket->ticket_revision,
        ];
    }

    public function snapshot(DiningTicket $ticket, array $extra = []): DiningTicketSnapshot
    {
        return DiningTicketSnapshot::fromTicket($ticket, $extra);
    }

    private function metadata(array $metadata): ?array
    {
        $metadata = array_filter(array_merge([
            'schema_version' => DiningTicketSnapshot::SCHEMA_VERSION,
        ], $metadata), fn ($value) => $value !== null);

        return $metadata ?: null;
    }
}
