<?php

namespace App\Services\Dining;

use App\Models\DiningTicket;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Values\Dining\DiningAuditPayload;

class DiningOperationAuditService
{
    public const TICKET_OPENED = 'DINING_TICKET_OPENED';
    public const TICKET_STATUS_CHANGED = 'DINING_TICKET_STATUS_CHANGED';
    public const GUEST_COUNT_CHANGED = 'DINING_GUEST_COUNT_CHANGED';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function recordTicketOpened(
        DiningTicket $ticket,
        User $actor,
        ?SalesMachineProfile $terminal,
        array $metadata = []
    ): void {
        $this->recordOperation(
            self::TICKET_OPENED,
            $ticket,
            null,
            $this->payloadForTicket($ticket),
            $actor,
            $terminal,
            metadata: $metadata,
        );
    }

    public function recordStatusChanged(
        DiningTicket $ticket,
        DiningAuditPayload $before,
        DiningAuditPayload $after,
        User $actor,
        ?SalesMachineProfile $terminal,
        ?string $reason = null,
        array $metadata = []
    ): void {
        $this->recordOperation(
            self::TICKET_STATUS_CHANGED,
            $ticket,
            $before,
            $after,
            $actor,
            $terminal,
            $reason,
            $metadata,
        );
    }

    public function recordGuestCountChanged(
        DiningTicket $ticket,
        int $beforeCount,
        int $afterCount,
        User $actor,
        ?SalesMachineProfile $terminal,
        ?string $reason = null,
        array $metadata = []
    ): void {
        $this->recordOperation(
            self::GUEST_COUNT_CHANGED,
            $ticket,
            $this->payloadForTicket($ticket, ['guest_count' => $beforeCount]),
            $this->payloadForTicket($ticket, ['guest_count' => $afterCount]),
            $actor,
            $terminal,
            $reason,
            $metadata,
        );
    }

    public function recordOperation(
        string $action,
        DiningTicket $ticket,
        ?DiningAuditPayload $before,
        ?DiningAuditPayload $after,
        User $actor,
        ?SalesMachineProfile $terminal,
        ?string $reason = null,
        array $metadata = []
    ): void {
        $actorContext = [
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal?->id,
        ];

        $this->auditLogger->log(
            $action,
            $ticket,
            $before?->with($actorContext)->toArray(),
            $after?->with($actorContext)->toArray(),
            $reason,
            metadata: array_filter(array_merge([
                'schema_version' => DiningAuditPayload::SCHEMA_VERSION,
                'actor_user_id' => $actor->id,
                'terminal_id' => $terminal?->id,
            ], $metadata), fn ($value) => $value !== null),
            actor: $actor,
        );
    }

    public function payloadForTicket(DiningTicket $ticket, array $extra = []): DiningAuditPayload
    {
        return DiningAuditPayload::fromTicket($ticket, $extra);
    }
}
