<?php

namespace App\Services\Dining;

use App\Models\DiningTicket;
use App\Models\DiningTicketEvent;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Values\Dining\DiningTimelinePayload;
use Illuminate\Support\Str;

class DiningTicketTimelineService
{
    public const OPENED = 'opened';
    public const STATUS_CHANGED = 'status_changed';
    public const GUEST_COUNT_CHANGED = 'guest_count_changed';
    public const TICKET_CLOSED = 'ticket_closed';

    public function recordOpened(
        DiningTicket $ticket,
        User $actor,
        ?SalesMachineProfile $terminal,
        DiningTimelinePayload $payload
    ): DiningTicketEvent {
        return $this->recordEvent($ticket, self::OPENED, $actor, $terminal, $payload);
    }

    public function recordStatusChanged(
        DiningTicket $ticket,
        string $fromStatus,
        string $toStatus,
        User $actor,
        ?SalesMachineProfile $terminal,
        DiningTimelinePayload $payload
    ): DiningTicketEvent {
        $eventType = $toStatus === DiningTicket::STATUS_CLOSED ? self::TICKET_CLOSED : self::STATUS_CHANGED;

        return $this->recordEvent($ticket, $eventType, $actor, $terminal, $payload);
    }

    public function recordGuestCountChanged(
        DiningTicket $ticket,
        int $beforeCount,
        int $afterCount,
        User $actor,
        ?SalesMachineProfile $terminal,
        DiningTimelinePayload $payload
    ): DiningTicketEvent {
        return $this->recordEvent($ticket, self::GUEST_COUNT_CHANGED, $actor, $terminal, $payload);
    }

    public function recordEvent(
        DiningTicket $ticket,
        string $eventType,
        User $actor,
        ?SalesMachineProfile $terminal,
        DiningTimelinePayload $payload
    ): DiningTicketEvent {
        return DiningTicketEvent::create([
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'dining_ticket_id' => $ticket->id,
            'event_uuid' => (string) Str::uuid(),
            'event_sequence' => $this->nextSequenceForTicket($ticket),
            'event_type' => $eventType,
            'summary' => $this->summary($eventType, $payload),
            'payload' => $payload->toArray(),
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal?->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function nextSequenceForTicket(DiningTicket $ticket): int
    {
        return ((int) DiningTicketEvent::query()
            ->where('dining_ticket_id', $ticket->id)
            ->max('event_sequence')) + 1;
    }

    private function summary(string $eventType, DiningTimelinePayload $payload): string
    {
        return match ($eventType) {
            self::OPENED => sprintf(
                'Ticket %s opened for Table %s.',
                $payload->get('ticket_number'),
                $payload->get('primary_table_number', 'unknown')
            ),
            self::GUEST_COUNT_CHANGED => sprintf(
                'Guest count changed from %s to %s.',
                $payload->get('before_guest_count'),
                $payload->get('after_guest_count')
            ),
            self::TICKET_CLOSED => 'Ticket closed.',
            self::STATUS_CHANGED => sprintf(
                'Ticket status changed from %s to %s.',
                $payload->get('from_status'),
                $payload->get('to_status')
            ),
            default => Str::headline($eventType).'.',
        };
    }
}
