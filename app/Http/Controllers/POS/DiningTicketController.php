<?php

namespace App\Http\Controllers\POS;

use App\Exceptions\Dining\DiningDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\OpenDiningTicketRequest;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\Dining\DiningTicketCheckoutService;
use App\Services\Dining\DiningTicketService;
use Illuminate\Http\JsonResponse;

class DiningTicketController extends Controller
{
    public function __construct(
        private readonly DiningTicketService $ticketService,
        private readonly DiningTicketCheckoutService $checkoutService,
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

    public function show(DiningTicket $ticket): JsonResponse
    {
        $ticket = DiningTicket::query()
            ->with(['items.product', 'primaryTableMapping.table'])
            ->with(['childTickets.sourceSale'])
            ->where('branch_id', $this->branchContext->getBranchId())
            ->whereKey($ticket->id)
            ->firstOrFail();

        return response()->json([
            'dining_ticket' => array_merge($this->ticketPayload($ticket), [
                'items' => $ticket->items
                    ->sortBy('created_at')
                    ->values()
                    ->map(fn (DiningTicketItem $item) => $this->itemPayload($item))
                    ->all(),
            ]),
        ]);
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
            'settlement' => $this->checkoutService->settlementPayload($ticket),
        ], fn ($value) => $value !== null);
    }

    private function itemPayload(DiningTicketItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name,
            'seat_number' => $item->seat_number,
            'quantity' => (string) $item->quantity,
            'unit_price_centavos' => $item->unit_price_centavos,
            'line_total_centavos' => $item->line_total_centavos,
            'status' => $item->status,
            'source_item_id' => $item->source_item_id,
            'course_no' => $item->course_no,
            'fire_group' => $item->fire_group,
            'hold_until' => optional($item->hold_until)->toIso8601String(),
            'preparation_station_id' => $item->preparation_station_id,
        ];
    }
}
