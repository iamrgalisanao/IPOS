<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\POS\ReceiptService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function __construct(
        protected ReceiptService $receiptService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Fetch the receipt payload for a given sale.
     * 
     * Strictly read-only.
     * Isolated by Tenant and Branch middleware.
     */
    public function show(Request $request, string $sale_id): JsonResponse
    {
        $sale = Sale::findOrFail($sale_id);
        // The 'tenant' and 'branch' middleware already ensure that the 
        // global scopes for tenant_id and branch_id are applied to the Sale model.
        // If the sale doesn't belong to the current context, it will 404 naturally.

        if ($sale->receipt_print_count >= 1) {
            $reason = trim($request->input('reprint_reason') ?? '');
            if (empty($reason)) {
                return response()->json([
                    'error' => 'REPRINT_REASON_REQUIRED',
                    'message' => 'A reprint reason is required for subsequent receipt prints.'
                ], 422);
            }
            $sale->last_reprint_reason = $reason;
            $sale->receipt_print_count += 1;
            $sale->save();

            // Systematically record to secure transaction logs inside the database
            $this->auditLogger->log(
                action: $sale->is_training_mode ? 'training_receipt_reprint' : 'receipt_reprint',
                auditable: $sale,
                reason: $reason,
                metadata: [
                    'print_count' => $sale->receipt_print_count,
                    'cashier_id'  => \Illuminate\Support\Facades\Auth::id(),
                    'is_training_mode' => (bool)$sale->is_training_mode,
                ]
            );
        } else {
            $sale->receipt_print_count = 1;
            $sale->save();

            if ($sale->is_training_mode) {
                $this->auditLogger->log(
                    action: 'training_receipt_printed',
                    auditable: $sale,
                    metadata: [
                        'print_count' => 1,
                        'cashier_id'  => \Illuminate\Support\Facades\Auth::id(),
                        'is_training_mode' => true,
                    ]
                );
            }
        }

        $receiptData = $this->receiptService->getReceiptData($sale);

        return response()->json($receiptData);
    }
}
