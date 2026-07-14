<?php

namespace App\Http\Controllers\POS;

use App\Exceptions\Dining\DiningDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\AssignDiningTicketItemSeatRequest;
use App\Http\Requests\Dining\MoveDiningTicketItemSeatRequest;
use App\Http\Requests\Dining\StoreDiningTicketItemRequest;
use App\Http\Requests\Dining\UpdateDiningTicketItemQuantityRequest;
use App\Http\Requests\Dining\VoidDiningTicketItemRequest;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\Dining\DiningTicketItemService;
use App\Values\Dining\AddDiningTicketItemCommand;
use App\Values\Dining\AssignDiningTicketItemSeatCommand;
use App\Values\Dining\ChangeDiningTicketItemQuantityCommand;
use App\Values\Dining\MoveDiningTicketItemSeatCommand;
use App\Values\Dining\VoidDiningTicketItemCommand;
use Illuminate\Http\JsonResponse;

class DiningTicketItemController extends Controller
{
    public function __construct(
        private readonly DiningTicketItemService $itemService,
        private readonly BranchContext $branchContext,
    ) {
    }

    public function store(StoreDiningTicketItemRequest $request, DiningTicket $ticket): JsonResponse
    {
        try {
            $item = $this->itemService->addItem(
                $this->scopedTicket($ticket),
                AddDiningTicketItemCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($this->itemResponse($item), 201);
    }

    public function quantity(
        UpdateDiningTicketItemQuantityRequest $request,
        DiningTicket $ticket,
        DiningTicketItem $item
    ): JsonResponse {
        try {
            $updated = $this->itemService->changeQuantity(
                $this->scopedTicket($ticket),
                $item,
                ChangeDiningTicketItemQuantityCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($this->itemResponse($updated));
    }

    public function seat(
        AssignDiningTicketItemSeatRequest $request,
        DiningTicket $ticket,
        DiningTicketItem $item
    ): JsonResponse {
        try {
            $updated = $this->itemService->assignSeat(
                $this->scopedTicket($ticket),
                $item,
                AssignDiningTicketItemSeatCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($this->itemResponse($updated));
    }

    public function moveSeat(
        MoveDiningTicketItemSeatRequest $request,
        DiningTicket $ticket,
        DiningTicketItem $item
    ): JsonResponse {
        try {
            $replacement = $this->itemService->moveToSeat(
                $this->scopedTicket($ticket),
                $item,
                MoveDiningTicketItemSeatCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        $source = $item->fresh();

        return response()->json(array_merge($this->itemResponse($replacement), [
            'source_item' => $this->itemPayload($source),
        ]));
    }

    public function void(
        VoidDiningTicketItemRequest $request,
        DiningTicket $ticket,
        DiningTicketItem $item
    ): JsonResponse {
        try {
            $voided = $this->itemService->voidItem(
                $this->scopedTicket($ticket),
                $item,
                VoidDiningTicketItemCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($this->itemResponse($voided));
    }

    private function scopedTicket(DiningTicket $ticket): DiningTicket
    {
        return DiningTicket::query()
            ->where('branch_id', $this->branchContext->getBranchId())
            ->whereKey($ticket->id)
            ->firstOrFail();
    }

    private function terminal($request): SalesMachineProfile
    {
        $terminal = $request->attributes->get('terminal_profile');

        if (!$terminal instanceof SalesMachineProfile) {
            throw new DiningDomainException('TERMINAL_CONTEXT_INVALID', 'Terminal context missing.', 403);
        }

        return $terminal;
    }

    private function itemResponse(DiningTicketItem $item): array
    {
        $ticket = $item->ticket()->firstOrFail();

        return [
            'dining_ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'status' => $ticket->status,
                'ticket_revision' => $ticket->ticket_revision,
                'subtotal_centavos' => $ticket->subtotal_centavos,
                'discount_centavos' => $ticket->discount_centavos,
                'service_charge_centavos' => $ticket->service_charge_centavos,
                'tax_centavos' => $ticket->tax_centavos,
                'grand_total_centavos' => $ticket->grand_total_centavos,
            ],
            'item' => $this->itemPayload($item),
        ];
    }

    private function itemPayload(?DiningTicketItem $item): ?array
    {
        if (!$item) {
            return null;
        }

        $item->loadMissing('product');

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
