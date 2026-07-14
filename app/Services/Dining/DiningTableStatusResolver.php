<?php

namespace App\Services\Dining;

use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Values\Dining\DiningTableStatusResult;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DiningTableStatusResolver
{
    public function resolve(DiningTable $table): DiningTableStatusResult
    {
        if ($table->relationLoaded('serviceArea') && !$table->serviceArea?->is_active) {
            return DiningTableStatusResult::unavailable('service_area_inactive');
        }

        if (!$table->is_active || $table->trashed()) {
            return DiningTableStatusResult::inactive();
        }

        if ($activeTicket = $this->activePrimaryTicket($table)) {
            return DiningTableStatusResult::occupied($this->activeTicketPayload($activeTicket));
        }

        return match ($table->operational_state) {
            DiningTable::STATE_AVAILABLE => DiningTableStatusResult::vacant(),
            DiningTable::STATE_RESERVED => DiningTableStatusResult::reserved(),
            DiningTable::STATE_CLEANING => DiningTableStatusResult::cleaning(),
            default => $this->unknownStateResult($table),
        };
    }

    /**
     * Resolve an eager-loaded table collection without issuing extra queries.
     *
     * @return Collection<string, DiningTableStatusResult>
     */
    public function resolveMany(Collection|EloquentCollection $tables): Collection
    {
        return $tables->mapWithKeys(fn (DiningTable $table) => [
            $table->id => $this->resolve($table),
        ]);
    }

    private function activePrimaryTicket(DiningTable $table): ?DiningTicket
    {
        if (!$table->relationLoaded('activeTicketMappings')) {
            return null;
        }

        $mapping = $table->activeTicketMappings
            ->first(fn ($ticketMapping) => $ticketMapping->relationLoaded('ticket')
                && $ticketMapping->ticket
                && in_array($ticketMapping->ticket->status, DiningTicket::ACTIVE_STATUSES, true));

        return $mapping?->ticket;
    }

    private function activeTicketPayload(DiningTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'guest_count' => $ticket->guest_count,
            'ticket_revision' => $ticket->ticket_revision,
            'opened_at' => optional($ticket->opened_at)->toIso8601String(),
        ];
    }

    private function unknownStateResult(DiningTable $table): DiningTableStatusResult
    {
        Log::warning('Unknown dining table operational state.', [
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'dining_table_id' => $table->id,
            'operational_state' => $table->operational_state,
        ]);

        return DiningTableStatusResult::unavailable('unknown_operational_state', [
            'operational_state' => $table->operational_state,
        ]);
    }
}
