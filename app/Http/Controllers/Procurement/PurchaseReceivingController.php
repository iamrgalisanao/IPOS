<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\Procurement\PurchaseReceivingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseReceivingController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Display a listing of the receiving vouchers.
     */
    public function index(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        $query = PurchaseReceiving::query()->with(['supplier', 'branch', 'receivedBy', 'purchaseOrder']);

        // Scope restriction
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $query->whereIn('branch_id', $assignedBranchIds);
        }

        // Apply filters
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $receivings = $query->latest()->get();

        // Extra details for selectors and listing
        $suppliers = Supplier::active()->get();

        $branchesQuery = Branch::active();
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $branchesQuery->whereIn('id', $assignedBranchIds);
        }
        $branches = $branchesQuery->get();

        return Inertia::render('Procurement/Receivings/Index', [
            'receivings' => $receivings,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'filters' => $request->only(['branch_id', 'status', 'supplier_id']),
        ]);
    }

    /**
     * Show the form for creating a new receiving voucher.
     */
    public function create(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();
        $suppliers = Supplier::active()->get();

        $branchesQuery = Branch::active();
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $branchesQuery->whereIn('id', $assignedBranchIds);
        }
        $branches = $branchesQuery->get();

        $products = Product::where('tenant_id', $tenantId)->get(['id', 'name', 'sku', 'cost_price']);

        // Handle PO-linked instantiation
        $purchaseOrder = null;
        if ($request->filled('purchase_order_id')) {
            $purchaseOrder = PurchaseOrder::where('tenant_id', $tenantId)
                ->with(['lines.product', 'supplier', 'branch'])
                ->findOrFail($request->input('purchase_order_id'));

            // Verify PO status is approved or sent
            if (!$purchaseOrder->isApproved() && !$purchaseOrder->isSent()) {
                return redirect()->route('procurement.purchase-orders.show', $purchaseOrder->id)
                    ->with('error', 'Only approved or sent purchase orders can be loaded for receiving.');
            }

            // Verify user access to PO's branch
            if (!$user->canAccessBranch($purchaseOrder->branch)) {
                abort(403, 'Unauthorized access to purchase order branch.');
            }
        }

        return Inertia::render('Procurement/Receivings/Create', [
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    /**
     * Store a newly created receiving draft.
     */
    public function store(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        // 1. Validate parameters
        $validated = $request->validate([
            'supplier_id' => 'required|uuid',
            'branch_id' => 'required|uuid',
            'purchase_order_id' => 'nullable|uuid',
            'received_at' => 'required|date',
            'delivery_ref_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.purchase_order_line_id' => 'nullable|uuid',
            'lines.*.received_quantity' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|gte:0',
            'lines.*.lot_number' => 'nullable|string|max:255',
            'lines.*.expiry_date' => 'nullable|date|after_or_equal:received_at',
            'lines.*.remarks' => 'nullable|string',
        ]);

        // Perishable validation: if product requires expiry tracking, require expiry_date
        $expiryErrors = [];
        foreach ($validated['lines'] as $index => $lineData) {
            $product = Product::where('tenant_id', $tenantId)->find($lineData['product_id']);
            if ($product && $product->expiry_tracking_enabled) {
                if (empty($lineData['expiry_date'])) {
                    $expiryErrors["lines.{$index}.expiry_date"] = ["The expiry date is required for perishable product: {$product->name}."];
                }
            }
        }
        if (!empty($expiryErrors)) {
            throw ValidationException::withMessages($expiryErrors);
        }

        // 2. Structural checks
        $supplier = Supplier::where('tenant_id', $tenantId)->active()->findOrFail($validated['supplier_id']);
        $branch = Branch::where('tenant_id', $tenantId)->active()->findOrFail($validated['branch_id']);

        if (!$user->canAccessBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        // If PO-linked, validate the PO boundary constraints
        if (!empty($validated['purchase_order_id'])) {
            $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($validated['purchase_order_id']);

            if ($po->branch_id !== $validated['branch_id']) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['The purchase order branch does not match the receiving branch.']
                ]);
            }

            if ($po->supplier_id !== $validated['supplier_id']) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['The purchase order supplier does not match the receiving supplier.']
                ]);
            }

            if (!$po->isApproved() && !$po->isSent()) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['Receiving drafts can only be generated from approved or sent purchase orders.']
                ]);
            }
        }

        // 3. Save atomically in draft state
        $purchaseReceiving = DB::transaction(function () use ($tenantId, $validated, $branch) {
            $receivingNumber = PurchaseReceiving::generateReceivingNumber($tenantId, $validated['branch_id'], $validated['received_at']);

            $receiving = PurchaseReceiving::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'receiving_number' => $receivingNumber,
                'status' => PurchaseReceiving::STATUS_DRAFT,
                'delivery_ref_number' => $validated['delivery_ref_number'] ?? null,
                'received_at' => $validated['received_at'],
                'total_received_amount' => 0.0000,
                'notes' => $validated['notes'] ?? null,
                'received_by' => auth()->id(),
            ]);

            $totalAmount = 0.0000;

            foreach ($validated['lines'] as $lineData) {
                // Ensure product ownership
                Product::where('tenant_id', $tenantId)->findOrFail($lineData['product_id']);

                // Calculate ordered quantity from linked PO line, if exists
                $orderedQty = 0.0000;
                if (!empty($lineData['purchase_order_line_id'])) {
                    $poLine = PurchaseOrderLine::findOrFail($lineData['purchase_order_line_id']);
                    $orderedQty = $poLine->ordered_quantity;
                }

                $qty = (float) $lineData['received_quantity'];
                $cost = (float) $lineData['unit_cost'];
                $lineTotal = $qty * $cost;

                PurchaseReceivingLine::create([
                    'purchase_receiving_id' => $receiving->id,
                    'purchase_order_line_id' => $lineData['purchase_order_line_id'] ?? null,
                    'product_id' => $lineData['product_id'],
                    'ordered_quantity' => $orderedQty,
                    'received_quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'remarks' => $lineData['remarks'] ?? null,
                ]);

                $totalAmount += $lineTotal;
            }

            $receiving->update(['total_received_amount' => $totalAmount]);

            return $receiving;
        });

        // 4. Log audit event
        $this->auditLogger->log(
            action: 'purchase_receiving_created',
            auditable: $purchaseReceiving,
            metadata: ['receiving_number' => $purchaseReceiving->receiving_number, 'amount' => $purchaseReceiving->total_received_amount]
        );

        return redirect()->route('procurement.receivings.show', $purchaseReceiving->id)
            ->with('success', 'Receiving draft created.');
    }

    /**
     * Display purchase receiving details.
     */
    public function show(PurchaseReceiving $purchaseReceiving)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseReceiving->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        $purchaseReceiving->load(['supplier', 'branch', 'purchaseOrder', 'receivedBy', 'postedBy', 'lines.product']);

        return Inertia::render('Procurement/Receivings/Show', [
            'purchaseReceiving' => $purchaseReceiving,
        ]);
    }

    /**
     * Show form for editing a draft.
     */
    public function edit(PurchaseReceiving $purchaseReceiving)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseReceiving->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseReceiving->canBeEdited()) {
            return redirect()->route('procurement.receivings.show', $purchaseReceiving->id)
                ->with('error', 'Only draft receiving vouchers can be edited.');
        }

        $suppliers = Supplier::active()->get();
        $branchesQuery = Branch::active();
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $branchesQuery->whereIn('id', $assignedBranchIds);
        }
        $branches = $branchesQuery->get();

        $tenantId = app(TenantContext::class)->getTenantId();
        $products = Product::where('tenant_id', $tenantId)->get(['id', 'name', 'sku', 'cost_price']);

        $purchaseReceiving->load('lines.product');

        return Inertia::render('Procurement/Receivings/Edit', [
            'purchaseReceiving' => $purchaseReceiving,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
        ]);
    }

    /**
     * Update draft details and lines.
     */
    public function update(Request $request, PurchaseReceiving $purchaseReceiving)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        if (!$user->canAccessBranch($purchaseReceiving->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseReceiving->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft receiving vouchers can be edited.']
            ]);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|uuid',
            'branch_id' => 'required|uuid',
            'purchase_order_id' => 'nullable|uuid',
            'received_at' => 'required|date',
            'delivery_ref_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.purchase_order_line_id' => 'nullable|uuid',
            'lines.*.received_quantity' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|gte:0',
            'lines.*.lot_number' => 'nullable|string|max:255',
            'lines.*.expiry_date' => 'nullable|date|after_or_equal:received_at',
            'lines.*.remarks' => 'nullable|string',
        ]);

        // Perishable validation: if product requires expiry tracking, require expiry_date
        $expiryErrors = [];
        foreach ($validated['lines'] as $index => $lineData) {
            $product = Product::where('tenant_id', $tenantId)->find($lineData['product_id']);
            if ($product && $product->expiry_tracking_enabled) {
                if (empty($lineData['expiry_date'])) {
                    $expiryErrors["lines.{$index}.expiry_date"] = ["The expiry date is required for perishable product: {$product->name}."];
                }
            }
        }
        if (!empty($expiryErrors)) {
            throw ValidationException::withMessages($expiryErrors);
        }

        Supplier::where('tenant_id', $tenantId)->active()->findOrFail($validated['supplier_id']);
        $branch = Branch::where('tenant_id', $tenantId)->active()->findOrFail($validated['branch_id']);
        if (!$user->canAccessBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        if (!empty($validated['purchase_order_id'])) {
            $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($validated['purchase_order_id']);

            if ($po->branch_id !== $validated['branch_id']) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['The purchase order branch does not match the receiving branch.']
                ]);
            }

            if ($po->supplier_id !== $validated['supplier_id']) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['The purchase order supplier does not match the receiving supplier.']
                ]);
            }

            if (!$po->isApproved() && !$po->isSent()) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['Receiving drafts can only be generated from approved or sent purchase orders.']
                ]);
            }
        }

        DB::transaction(function () use ($tenantId, $purchaseReceiving, $validated) {
            $purchaseReceiving->lines()->delete();

            $totalAmount = 0.0000;

            foreach ($validated['lines'] as $lineData) {
                Product::where('tenant_id', $tenantId)->findOrFail($lineData['product_id']);

                $orderedQty = 0.0000;
                if (!empty($lineData['purchase_order_line_id'])) {
                    $poLine = PurchaseOrderLine::findOrFail($lineData['purchase_order_line_id']);
                    $orderedQty = $poLine->ordered_quantity;
                }

                $qty = (float) $lineData['received_quantity'];
                $cost = (float) $lineData['unit_cost'];
                $lineTotal = $qty * $cost;

                PurchaseReceivingLine::create([
                    'purchase_receiving_id' => $purchaseReceiving->id,
                    'purchase_order_line_id' => $lineData['purchase_order_line_id'] ?? null,
                    'product_id' => $lineData['product_id'],
                    'ordered_quantity' => $orderedQty,
                    'received_quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'remarks' => $lineData['remarks'] ?? null,
                ]);

                $totalAmount += $lineTotal;
            }

            // Regenerate unique receiving_number if branch or received_at changed
            $receivingNumber = $purchaseReceiving->receiving_number;
            $oldReceivedAt = $purchaseReceiving->received_at ? $purchaseReceiving->received_at->format('Y-m-d') : null;
            if ($purchaseReceiving->branch_id !== $validated['branch_id'] || $oldReceivedAt !== $validated['received_at']) {
                $receivingNumber = PurchaseReceiving::generateReceivingNumber($tenantId, $validated['branch_id'], $validated['received_at']);
            }

            $purchaseReceiving->update([
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'received_at' => $validated['received_at'],
                'delivery_ref_number' => $validated['delivery_ref_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'receiving_number' => $receivingNumber,
                'total_received_amount' => $totalAmount,
            ]);
        });

        $this->auditLogger->log(
            action: 'purchase_receiving_updated',
            auditable: $purchaseReceiving,
            metadata: ['receiving_number' => $purchaseReceiving->receiving_number, 'amount' => $purchaseReceiving->total_received_amount]
        );

        return redirect()->route('procurement.receivings.show', $purchaseReceiving->id)
            ->with('success', 'Receiving draft updated successfully.');
    }

    /**
     * Cancel a receiving draft.
     */
    public function cancel(PurchaseReceiving $purchaseReceiving)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseReceiving->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseReceiving->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['Terminal receiving vouchers cannot be cancelled.']
            ]);
        }

        $purchaseReceiving->update([
            'status' => PurchaseReceiving::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'purchase_receiving_cancelled',
            auditable: $purchaseReceiving,
            metadata: ['receiving_number' => $purchaseReceiving->receiving_number]
        );

        return back()->with('success', 'Receiving draft has been cancelled.');
    }

    /**
     * Post a receiving draft to update inventory and WAC.
     */
    public function post(PurchaseReceiving $purchaseReceiving, PurchaseReceivingPostingService $postingService)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseReceiving->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseReceiving->canBePosted()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft receiving vouchers can be posted.']
            ]);
        }

        $postingService->post($purchaseReceiving);

        return redirect()->route('procurement.receivings.show', $purchaseReceiving->id)
            ->with('success', 'Receiving voucher posted successfully and inventory updated.');
    }

    /**
     * Export purchase receivings to CSV.
     */
    public function export(Request $request, \App\Services\Procurement\PurchaseReceivingCsvExportService $exportService)
    {
        $user = auth()->user();
        $query = PurchaseReceiving::query()->with(['supplier', 'branch', 'purchaseOrder', 'receivedBy', 'postedBy', 'lines.product']);

        // Scope restriction
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $query->whereIn('branch_id', $assignedBranchIds);
        }

        // Apply filters
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $receivings = $query->latest()->get();

        $csv = $exportService->export($receivings, $user);
        $filename = 'purchase_receivings_export_' . now()->format('Ymd_His') . '.csv';

        // Audit Log
        $this->auditLogger->log(
            action: 'purchase_receivings_exported',
            auditable: null,
            metadata: [
                'tenant_id' => app(TenantContext::class)->getTenantId(),
                'record_id' => 'bulk',
                'record_number' => 'all',
                'actor_id' => $user->id,
                'export_format' => 'CSV',
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Export a single purchase receiving to CSV.
     */
    public function exportOne(PurchaseReceiving $purchaseReceiving, \App\Services\Procurement\PurchaseReceivingCsvExportService $exportService)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseReceiving->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        $purchaseReceiving->load(['lines.product', 'branch', 'supplier', 'purchaseOrder', 'receivedBy', 'postedBy']);

        $csv = $exportService->export(collect([$purchaseReceiving]), $user);
        $filename = 'purchase_receiving_' . $purchaseReceiving->receiving_number . '_' . now()->format('Ymd_His') . '.csv';

        // Audit Log
        $this->auditLogger->log(
            action: 'purchase_receiving_exported',
            auditable: $purchaseReceiving,
            metadata: [
                'tenant_id' => app(TenantContext::class)->getTenantId(),
                'branch_id' => $purchaseReceiving->branch_id,
                'record_id' => $purchaseReceiving->id,
                'record_number' => $purchaseReceiving->receiving_number,
                'actor_id' => $user->id,
                'export_format' => 'CSV',
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
