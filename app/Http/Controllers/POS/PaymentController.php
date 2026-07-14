<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Requests\RecordSplitPaymentRequest;
use App\Services\POS\PaymentRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    protected PaymentRecordingService $paymentService;

    public function __construct(PaymentRecordingService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Record a single payment for a sale.
     */
    public function store(RecordPaymentRequest $request, string $saleId): JsonResponse
    {
        try {
            $payment = $this->paymentService->record($saleId, $request->validated(), Auth::user());

            return response()->json([
                'status' => 'recorded',
                'sale_id' => $payment->sale_id,
                'payment_id' => $payment->id,
                'sale_status' => 'paid',
                'amount_paid' => $payment->amount,
                'remaining_balance' => '0.0000',
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (\RuntimeException $e) {
            return $this->handleInventoryException($e);
        }
    }

    /**
     * Record multiple payments for a sale.
     */
    public function storeSplit(RecordSplitPaymentRequest $request, string $saleId): JsonResponse
    {
        try {
            $payments = $this->paymentService->recordSplit($saleId, $request->validated()['payments'], Auth::user());

            $totalAmount = $payments->sum('amount');
            $diningFinalization = $payments->first()?->sale?->getAttribute('dining_finalization');

            return response()->json(array_filter([
                'status' => 'recorded',
                'sale_id' => $saleId,
                'sale_status' => 'paid',
                'payment_count' => $payments->count(),
                'amount_paid' => number_format($totalAmount, 4, '.', ''),
                'remaining_balance' => '0.0000',
                'payments' => $payments->map(function ($p) {
                    return [
                        'payment_id' => $p->id,
                        'payment_method_id' => $p->payment_method_id,
                        'amount' => $p->amount,
                        'reference_number' => $p->reference_number,
                    ];
                }),
                'dining_ticket' => $diningFinalization['dining_ticket'] ?? null,
                'parent_settlement' => $diningFinalization['parent_settlement'] ?? null,
            ], fn ($value) => $value !== null));
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (\RuntimeException $e) {
            return $this->handleInventoryException($e);
        }
    }

    /**
     * Centralized validation exception handler.
     */
    protected function handleValidationException(ValidationException $e): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $e->errors(),
        ], 422);
    }

    /**
     * Convert technical inventory exceptions into cashier-friendly messages.
     */
    protected function handleInventoryException(\RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        
        if (str_contains($message, 'Insufficient stock') || str_contains($message, 'Inventory record not found') || str_contains($message, 'No active unit conversion rule found')) {
            return response()->json([
                'message' => 'Payment could not be completed because one or more items no longer have enough stock at this branch.',
                'errors' => [
                    'inventory' => [$message]
                ]
            ], 422);
        }

        if (str_contains($message, 'Sale already paid') || str_contains($message, 'Amount mismatch') || str_contains($message, 'Sale not found')) {
             return response()->json([
                'message' => 'The payment could not be processed.',
                'errors' => [
                    'payment' => [$message]
                ]
            ], 422);
        }

        throw $e;
    }
}
