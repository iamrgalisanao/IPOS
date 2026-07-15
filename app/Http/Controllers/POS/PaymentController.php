<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Requests\RecordSplitPaymentRequest;
use App\Exceptions\StoreCredit\StoreCreditAlreadyRedeemedException;
use App\Exceptions\StoreCredit\StoreCreditLedgerAccountStateException;
use App\Exceptions\StoreCredit\StoreCreditLedgerCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditLedgerIdempotencyDriftException;
use App\Exceptions\StoreCredit\StoreCreditLedgerInsufficientBalanceException;
use App\Exceptions\StoreCredit\StoreCreditLedgerSourceConflictException;
use App\Exceptions\Loyalty\LoyaltyLedgerAccountStateException;
use App\Exceptions\Loyalty\LoyaltyLedgerIdempotencyDriftException;
use App\Exceptions\Loyalty\LoyaltyLedgerInsufficientBalanceException;
use App\Exceptions\Loyalty\LoyaltyLedgerSourceConflictException;
use App\Exceptions\Loyalty\LoyaltyRedemptionException;
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
            $payment = $this->paymentService->record($saleId, $request->validated(), Auth::user(), $request->header('Idempotency-Key'));

            return response()->json([
                'status' => 'recorded',
                'sale_id' => $payment->sale_id,
                'payment_id' => $payment->id,
                'sale_status' => 'paid',
                'amount_paid' => $payment->amount,
                'remaining_balance' => '0.0000',
                'store_credit' => $this->storeCreditPayload($payment),
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (
            StoreCreditAlreadyRedeemedException
            | StoreCreditLedgerAccountStateException
            | StoreCreditLedgerCurrencyMismatchException
            | StoreCreditLedgerIdempotencyDriftException
            | StoreCreditLedgerInsufficientBalanceException
            | StoreCreditLedgerSourceConflictException $e
        ) {
            return $this->handleStoreCreditException($e);
        } catch (
            LoyaltyLedgerAccountStateException
            | LoyaltyLedgerIdempotencyDriftException
            | LoyaltyLedgerInsufficientBalanceException
            | LoyaltyLedgerSourceConflictException
            | LoyaltyRedemptionException $e
        ) {
            return $this->handleLoyaltyException($e);
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
            $payments = $this->paymentService->recordSplit($saleId, $request->validated()['payments'], Auth::user(), $request->header('Idempotency-Key'));

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
                        'store_credit' => $this->storeCreditPayload($p),
                    ];
                }),
                'dining_ticket' => $diningFinalization['dining_ticket'] ?? null,
                'parent_settlement' => $diningFinalization['parent_settlement'] ?? null,
            ], fn ($value) => $value !== null));
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (
            StoreCreditAlreadyRedeemedException
            | StoreCreditLedgerAccountStateException
            | StoreCreditLedgerCurrencyMismatchException
            | StoreCreditLedgerIdempotencyDriftException
            | StoreCreditLedgerInsufficientBalanceException
            | StoreCreditLedgerSourceConflictException $e
        ) {
            return $this->handleStoreCreditException($e);
        } catch (
            LoyaltyLedgerAccountStateException
            | LoyaltyLedgerIdempotencyDriftException
            | LoyaltyLedgerInsufficientBalanceException
            | LoyaltyLedgerSourceConflictException
            | LoyaltyRedemptionException $e
        ) {
            return $this->handleLoyaltyException($e);
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

    protected function handleStoreCreditException(\RuntimeException $e): JsonResponse
    {
        $code = match (true) {
            $e instanceof StoreCreditLedgerInsufficientBalanceException => 'INSUFFICIENT_STORE_CREDIT_BALANCE',
            $e instanceof StoreCreditLedgerAccountStateException => 'STORE_CREDIT_ACCOUNT_NOT_REDEEMABLE',
            $e instanceof StoreCreditLedgerIdempotencyDriftException => 'IDEMPOTENCY_DRIFT',
            $e instanceof StoreCreditAlreadyRedeemedException,
            $e instanceof StoreCreditLedgerSourceConflictException => 'STORE_CREDIT_REDEMPTION_ALREADY_POSTED',
            default => 'STORE_CREDIT_REDEMPTION_FAILED',
        };

        return response()->json([
            'message' => $e->getMessage(),
            'code' => $code,
        ], 409);
    }

    protected function handleLoyaltyException(\RuntimeException $e): JsonResponse
    {
        $code = match (true) {
            $e instanceof LoyaltyLedgerInsufficientBalanceException => 'INSUFFICIENT_LOYALTY_POINTS',
            $e instanceof LoyaltyLedgerAccountStateException => 'LOYALTY_ACCOUNT_NOT_REDEEMABLE',
            $e instanceof LoyaltyLedgerIdempotencyDriftException => 'IDEMPOTENCY_DRIFT',
            $e instanceof LoyaltyLedgerSourceConflictException => 'LOYALTY_REDEMPTION_ALREADY_POSTED',
            default => 'LOYALTY_REDEMPTION_FAILED',
        };

        return response()->json([
            'message' => $e->getMessage(),
            'code' => $code,
        ], 409);
    }

    protected function storeCreditPayload($payment): ?array
    {
        $redemption = $payment->relationLoaded('storeCreditRedemption')
            ? $payment->storeCreditRedemption
            : $payment->storeCreditRedemption()->with('ledgerEntry')->first();

        if (!$redemption) {
            return null;
        }

        return [
            'customer_financial_account_id' => $redemption->customer_financial_account_id,
            'redemption_id' => $redemption->id,
            'ledger_entry_id' => $redemption->store_credit_ledger_entry_id,
            'amount_centavos' => $redemption->amount_centavos,
            'currency_code' => $redemption->currency_code,
            'authorized_balance_centavos' => $redemption->authorized_balance_centavos,
        ];
    }
}
