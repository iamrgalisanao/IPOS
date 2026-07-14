<?php

namespace App\Http\Controllers\POS;

use App\Exceptions\Dining\DiningDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\OpenDiningTicketRequest;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\Dining\DiningTicketService;
use Illuminate\Http\JsonResponse;

class DiningTicketController extends Controller
{
    public function __construct(
        private readonly DiningTicketService $ticketService,
        private readonly BranchContext $branchContext,
    ) {
    }

    public function store(OpenDiningTicketRequest $request): JsonResponse
    {
        $table = DiningTable::query()
            ->where('branch_id', $this->branchContext->getBranchId())
            ->whereKey($request->validated('dining_table_id'))
            ->firstOrFail();

        $terminal = $request->attributes->get('terminal_profile');

        try {
            $ticket = $this->ticketService->openTicket(
                $table,
                $request->validated(),
                $request->user(),
                $terminal instanceof SalesMachineProfile ? $terminal : null
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json([
            'dining_ticket' => $this->ticketPayload($ticket),
        ], $ticket->getAttribute('idempotent_replay') ? 200 : 201);
    }

    private function ticketPayload(DiningTicket $ticket): array
    {
        $primaryTable = $ticket->primaryTableMapping?->table;

        return array_filter([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'guest_count' => $ticket->guest_count,
            'ticket_revision' => $ticket->ticket_revision,
            'subtotal_centavos' => $ticket->subtotal_centavos,
            'discount_centavos' => $ticket->discount_centavos,
            'service_charge_centavos' => $ticket->service_charge_centavos,
            'tax_centavos' => $ticket->tax_centavos,
            'grand_total_centavos' => $ticket->grand_total_centavos,
            'opened_at' => optional($ticket->opened_at)->toIso8601String(),
            'idempotent_replay' => $ticket->getAttribute('idempotent_replay') ?: null,
            'primary_table' => $primaryTable ? [
                'id' => $primaryTable->id,
                'table_number' => $primaryTable->table_number,
                'service_area_id' => $primaryTable->service_area_id,
            ] : null,
        ], fn ($value) => $value !== null);
    }
}
