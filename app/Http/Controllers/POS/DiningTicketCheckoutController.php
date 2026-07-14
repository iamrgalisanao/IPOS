<?php

namespace App\Http\Controllers\POS;

use App\Exceptions\Dining\DiningDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\CreateDiningTicketSaleRequest;
use App\Models\DiningTicket;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\Dining\DiningTicketCheckoutService;
use Illuminate\Http\JsonResponse;

class DiningTicketCheckoutController extends Controller
{
    public function __construct(
        private readonly DiningTicketCheckoutService $checkoutService,
        private readonly BranchContext $branchContext,
    ) {
    }

    public function createSale(CreateDiningTicketSaleRequest $request, DiningTicket $ticket): JsonResponse
    {
        $ticket = DiningTicket::query()
            ->where('branch_id', $this->branchContext->getBranchId())
            ->whereKey($ticket->id)
            ->firstOrFail();

        $terminal = $request->attributes->get('terminal_profile');

        try {
            $result = $this->checkoutService->createSale(
                $ticket,
                $request->user(),
                $terminal instanceof SalesMachineProfile ? $terminal : null,
                $request->validated('checkout_request_uuid'),
                (int) $request->validated('expected_ticket_revision'),
                (bool) $request->validated('is_training_mode', false),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($result);
    }
}
