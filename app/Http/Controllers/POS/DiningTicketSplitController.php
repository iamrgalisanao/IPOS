<?php

namespace App\Http\Controllers\POS;

use App\Exceptions\Dining\DiningDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\SplitDiningTicketByItemQuantityRequest;
use App\Http\Requests\Dining\SplitDiningTicketBySeatRequest;
use App\Models\DiningTicket;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\Dining\BillSplitAllocatorService;
use App\Values\Dining\SplitDiningTicketByItemQuantityCommand;
use App\Values\Dining\SplitDiningTicketBySeatCommand;
use Illuminate\Http\JsonResponse;

class DiningTicketSplitController extends Controller
{
    public function __construct(
        private readonly BillSplitAllocatorService $splitAllocatorService,
        private readonly BranchContext $branchContext,
    ) {
    }

    public function bySeat(SplitDiningTicketBySeatRequest $request, DiningTicket $ticket): JsonResponse
    {
        try {
            $result = $this->splitAllocatorService->splitBySeat(
                $this->scopedTicket($ticket),
                SplitDiningTicketBySeatCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($this->responsePayload($result), $result['idempotent_replay'] ? 200 : 201);
    }

    public function byItemQuantity(SplitDiningTicketByItemQuantityRequest $request, DiningTicket $ticket): JsonResponse
    {
        try {
            $result = $this->splitAllocatorService->splitByItemQuantity(
                $this->scopedTicket($ticket),
                SplitDiningTicketByItemQuantityCommand::fromValidated($request->validated()),
                $request->user(),
                $this->terminal($request),
            );
        } catch (DiningDomainException $exception) {
            return response()->json($exception->toResponsePayload(), $exception->status());
        }

        return response()->json($this->responsePayload($result), $result['idempotent_replay'] ? 200 : 201);
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

    private function responsePayload(array $result): array
    {
        unset($result['idempotent_replay']);

        return $result;
    }
}
