<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Models\ManualRefundRequest;
use App\Services\POS\VoidService;
use App\Services\POS\RefundService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Values\POS\RefundPayoutCommand;
use App\Exceptions\StoreCredit\StoreCreditRefundAccountConflictException;
use App\Exceptions\StoreCredit\StoreCreditRefundAlreadyIssuedException;
use App\Exceptions\StoreCredit\StoreCreditRefundCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditRefundOfflineNotAllowedException;
use App\Exceptions\StoreCredit\StoreCreditLedgerAccountStateException;
use App\Exceptions\StoreCredit\StoreCreditLedgerCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditLedgerIdempotencyDriftException;
use App\Exceptions\StoreCredit\StoreCreditLedgerSourceConflictException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class VoidRefundController extends Controller
{
    public function __construct(
        protected VoidService $voidService,
        protected RefundService $refundService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Perform a full-sale void.
     */
    public function void(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        // 1. Permission Check
        if (!$user->hasPermission('pos.void')) {
            $validated = $this->verifySupervisor($request, 'pos.void');
            if ($validated instanceof JsonResponse) {
                return $validated;
            }
        }

        // 2. Timing Guard (Same shift verification)
        $payment = $sale->payments()->first();
        $shift = $payment ? $payment->shift : null;

        if (!$shift || $shift->status !== Shift::STATUS_OPEN) {
            return response()->json([
                'success' => false,
                'code' => 'VOID_BLOCKED_SHIFT_CLOSED',
                'message' => 'This transaction can no longer be voided because the shift is already closed. Please process a refund instead.',
                'next_action' => 'CREATE_REFUND_REQUEST'
            ], 409);
        }

        try {
            $reasonCode = $request->input('reason_code', 'CANCELLATION');
            $reasonNotes = $request->input('reason_notes');

            $void = $this->voidService->void($sale, $reasonCode, $reasonNotes);

            return response()->json([
                'success' => true,
                'message' => 'Transaction voided successfully.',
                'data' => [
                    'void_id' => $void->id,
                    'sale_id' => $sale->id,
                    'status' => 'voided'
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'VOID_FAILED',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Perform a partial or full refund.
     */
    public function refund(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        // 1. Permission Check
        if (!$user->hasPermission('pos.refund')) {
            $validated = $this->verifySupervisor($request, 'pos.refund');
            if ($validated instanceof JsonResponse) {
                return $validated;
            }
        }

        $items = $request->input('items', []);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_REFUND_QUANTITY',
                'message' => 'At least one item must be selected for refund.'
            ], 422);
        }

        // Validate quantities format
        foreach ($items as $item) {
            if (!isset($item['sale_item_id']) || !isset($item['quantity']) || (float)$item['quantity'] <= 0) {
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_REFUND_QUANTITY',
                    'message' => 'Invalid refund quantity provided.'
                ], 422);
            }
        }

        $payment = $sale->payments()->first();
        $originalPaymentMethod = $payment ? $payment->paymentMethod->name : 'cash';
        $isElectronic = in_array(strtolower($originalPaymentMethod), ['card', 'credit_card', 'gcash', 'maya', 'e-wallet']);

        $shift = $payment ? $payment->shift : null;
        $isShiftClosed = !$shift || $shift->status !== Shift::STATUS_OPEN;

        $payoutMethod = $request->input('payout_method', 'electronic'); // electronic, cash_exception, or store_credit

        if ($payoutMethod === RefundPayoutCommand::METHOD_STORE_CREDIT && !$request->input('customer_financial_account_id')) {
            return response()->json([
                'success' => false,
                'code' => 'STORE_CREDIT_ACCOUNT_REQUIRED',
                'message' => 'A customer financial account is required for store credit refunds.'
            ], 422);
        }

        // 2. Closed-shift Electronic Payment Rule
        if ($isElectronic && $isShiftClosed && $payoutMethod !== 'cash_exception') {
            // Default: Route to Manual Electronic Refund Queue
            try {
                $totalRefundAmount = 0;
                foreach ($items as $item) {
                    $saleItem = \App\Models\SaleItem::findOrFail($item['sale_item_id']);
                    $totalRefundAmount += ((float)$item['quantity'] / $saleItem->quantity) * $saleItem->line_total;
                }

                $manualRequest = DB::transaction(function () use ($sale, $originalPaymentMethod, $totalRefundAmount, $user, $request) {
                    return ManualRefundRequest::create([
                        'tenant_id' => $sale->tenant_id,
                        'branch_id' => $sale->branch_id,
                        'sale_id' => $sale->id,
                        'original_payment_method' => $originalPaymentMethod,
                        'requested_refund_amount' => $totalRefundAmount,
                        'requested_by' => $user->id,
                        'status' => 'pending_approval',
                        'customer_refund_channel' => $request->input('customer_refund_channel', 'bank_transfer'),
                        'customer_reference_details' => $request->input('customer_reference_details'),
                        'finance_notes' => $request->input('finance_notes')
                    ]);
                });

                return response()->json([
                    'success' => true,
                    'code' => 'MANUAL_REFUND_REQUESTED',
                    'message' => 'Refund request has been successfully routed to the Manual Electronic Refund Approval Queue.',
                    'data' => [
                        'manual_refund_request_id' => $manualRequest->id,
                        'status' => 'pending_approval'
                    ]
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'code' => 'REFUND_REQUEST_FAILED',
                    'message' => $e->getMessage()
                ], 422);
            }
        }

        // 3. Cash Exception Path or Standard Refund
        if ($payoutMethod === 'cash_exception') {
            // Cash payouts on electronic payments require supervisor override
            $validated = $this->verifySupervisor($request, 'pos.refund');
            if ($validated instanceof JsonResponse) {
                return $validated;
            }

            if (!$request->input('cash_exception_reason')) {
                return response()->json([
                    'success' => false,
                    'code' => 'MISSING_EXCEPTION_REASON',
                    'message' => 'A cash payout exception reason is required.'
                ], 422);
            }
        }

        try {
            $reasonCode = $request->input('reason_code', 'RETURN');
            $reasonNotes = $request->input('reason_notes');

            // Map frontend physical receipt to backend pending_inspection status
            // Cashier does not select restock/write-off, default to restock = false or classification = pending_inspection
            // In the DB, SaleRefundItem has restock_action. We pass 'pending_inspection' as the restock_action!
            $itemsToRefund = [];
            foreach ($items as $item) {
                $itemsToRefund[] = [
                    'sale_item_id' => $item['sale_item_id'],
                    'quantity' => $item['quantity'],
                    'restock_action' => 'pending_inspection' // Cashier intake only
                ];
            }

            $activeShift = $this->getActiveShift();
            $payoutCommand = new RefundPayoutCommand(
                payoutMethod: $payoutMethod,
                customerFinancialAccountId: $request->input('customer_financial_account_id'),
                idempotencyKey: $request->header('Idempotency-Key'),
                requestedBy: $user,
                approvalReference: $request->input('supervisor_email'),
                sourceChannel: 'pos',
            );

            $refund = $this->refundService->refund(
                $sale,
                $itemsToRefund,
                $reasonCode,
                $reasonNotes,
                $activeShift?->id,
                $payoutCommand
            );

            // Log Cash Drawer impact if payout method was cash (cash exception or standard cash)
            if (
                $payoutMethod !== RefundPayoutCommand::METHOD_STORE_CREDIT
                && ($payoutMethod === 'cash_exception' || strtolower($originalPaymentMethod) === 'cash')
            ) {
                if ($activeShift) {
                    $activeShift->cashDrawerEvents()->create([
                        'tenant_id' => $sale->tenant_id,
                        'branch_id' => $sale->branch_id,
                        'event_type' => 'payout',
                        'amount' => $refund->refund_total,
                        'description' => "Refund Cash Payout - Sale: {$sale->sale_number}, Reason: {$reasonNotes}",
                        'recorded_by' => $user->id
                    ]);
                }
            }

            $storeCredit = $refund->storeCreditIssuance;

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully.',
                'data' => [
                    'refund_id' => $refund->id,
                    'sale_id' => $sale->id,
                    'refund_total' => $refund->refund_total,
                    'status' => 'refunded',
                    'payout_method' => $payoutMethod,
                    'store_credit' => $storeCredit ? [
                        'customer_financial_account_id' => $storeCredit->customer_financial_account_id,
                        'ledger_entry_id' => $storeCredit->store_credit_ledger_entry_id,
                        'amount_centavos' => $storeCredit->amount_centavos,
                        'currency_code' => $storeCredit->currency_code,
                    ] : null,
                ]
            ]);
        } catch (
            StoreCreditRefundAccountConflictException
            | StoreCreditRefundAlreadyIssuedException
            | StoreCreditRefundCurrencyMismatchException
            | StoreCreditRefundOfflineNotAllowedException
            | StoreCreditLedgerAccountStateException
            | StoreCreditLedgerCurrencyMismatchException
            | StoreCreditLedgerIdempotencyDriftException
            | StoreCreditLedgerSourceConflictException $e
        ) {
            return response()->json([
                'success' => false,
                'code' => 'STORE_CREDIT_REFUND_FAILED',
                'message' => $e->getMessage()
            ], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'REFUND_FAILED',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Helper to verify supervisor credentials.
     */
    protected function verifySupervisor(Request $request, string $permission)
    {
        $email = $request->input('supervisor_email');
        $password = $request->input('supervisor_password');

        if (!$email || !$password) {
            return response()->json([
                'success' => false,
                'code' => 'SUPERVISOR_AUTH_REQUIRED',
                'message' => 'Supervisor authorization credentials are required for this action.'
            ], 403);
        }

        $supervisor = User::where('email', $email)->first();

        if (!$supervisor || !Hash::check($password, $supervisor->password)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_SUPERVISOR_CREDENTIALS',
                'message' => 'The supervisor credentials provided are invalid.'
            ], 403);
        }

        if (!$supervisor->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'code' => 'UNAUTHORIZED_SUPERVISOR',
                'message' => 'The supervisor does not have permission to authorize this action.'
            ], 403);
        }

        return true;
    }

    /**
     * Helper to get active shift.
     */
    protected function getActiveShift()
    {
        $branch = $this->branchContext->getBranch();
        if (!$branch) return null;

        return app(\App\Services\Shift\ShiftService::class)->getActiveShiftFor(Auth::user(), $branch);
    }
}
