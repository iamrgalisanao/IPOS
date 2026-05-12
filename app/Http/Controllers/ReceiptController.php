<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\POS\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function __construct(
        protected ReceiptService $receiptService
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

        $receiptData = $this->receiptService->getReceiptData($sale);

        return response()->json($receiptData);
    }
}
