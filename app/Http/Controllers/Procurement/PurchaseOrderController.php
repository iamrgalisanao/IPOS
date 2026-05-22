<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Display a listing of the purchase orders.
     */
    public function index(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        $query = PurchaseOrder::query()->with(['supplier', 'branch', 'createdBy']);

        // Branch Manager & Store Clerk scope restriction
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

        $purchaseOrders = $query->latest()->get();

        // Extra details for listing filters and selectors
        $suppliers = Supplier::active()->get();

        $branchesQuery = Branch::active();
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $branchesQuery->whereIn('id', $assignedBranchIds);
        }
        $branches = $branchesQuery->get();

        return Inertia::render('Procurement/PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'filters' => $request->only(['branch_id', 'status', 'supplier_id']),
        ]);
    }

    /**
     * Show the form for creating a new purchase order.
     */
    public function create()
    {
        $user = auth()->user();
        $suppliers = Supplier::active()->get();

        $branchesQuery = Branch::active();
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $branchesQuery->whereIn('id', $assignedBranchIds);
        }
        $branches = $branchesQuery->get();

        // Get list of active inventory products for line addition
        $tenantId = app(TenantContext::class)->getTenantId();
        $products = Product::where('tenant_id', $tenantId)->get(['id', 'name', 'sku', 'cost_price']);

        return Inertia::render('Procurement/PurchaseOrders/Create', [
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
        ]);
    }

    /**
     * Store a newly created purchase order.
     */
    public function store(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        // 1. Core structural authorization checks
        $supplier = Supplier::where('tenant_id', $tenantId)->active()->findOrFail($request->input('supplier_id'));
        $branch = Branch::where('tenant_id', $tenantId)->active()->findOrFail($request->input('branch_id'));

        if (!$user->canAccessBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        // 2. Validate input parameters
        $validated = $request->validate([
            'supplier_id' => 'required|uuid',
            'branch_id' => 'required|uuid',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.ordered_quantity' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|gte:0',
        ]);

        // 3. Process database insertion atomically
        $purchaseOrder = DB::transaction(function () use ($tenantId, $validated, $branch, $request) {
            $poNumber = PurchaseOrder::generatePoNumber($tenantId, $validated['branch_id'], $validated['order_date']);

            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'po_number' => $poNumber,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
                'total_estimated_amount' => 0.0000,
            ]);

            $totalAmount = 0.0000;

            foreach ($validated['lines'] as $lineData) {
                // Ensure product belongs to same tenant
                $product = Product::where('tenant_id', $tenantId)->findOrFail($lineData['product_id']);

                $lineCost = (float) $lineData['unit_cost'];
                $lineQty = (float) $lineData['ordered_quantity'];
                $lineTotal = $lineQty * $lineCost;

                PurchaseOrderLine::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $lineData['product_id'],
                    'ordered_quantity' => $lineQty,
                    'unit_cost' => $lineCost,
                    'line_total' => $lineTotal,
                ]);

                $totalAmount += $lineTotal;
            }

            // Update Po estimate sum
            $po->update(['total_estimated_amount' => $totalAmount]);

            return $po;
        });

        // 4. Audit Log
        $this->auditLogger->log(
            action: 'purchase_order_created',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number, 'amount' => $purchaseOrder->total_estimated_amount]
        );

        return redirect()->route('procurement.purchase-orders.show', $purchaseOrder->id)
            ->with('success', 'Purchase order draft created.');
    }

    /**
     * Display purchase order details.
     */
    public function show(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        $purchaseOrder->load(['supplier', 'branch', 'createdBy', 'approvedBy', 'lines.product']);

        return Inertia::render('Procurement/PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    /**
     * Show form for editing a draft.
     */
    public function edit(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseOrder->canBeEdited()) {
            return redirect()->route('procurement.purchase-orders.show', $purchaseOrder->id)
                ->with('error', 'Only draft purchase orders can be edited.');
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

        $purchaseOrder->load('lines');

        return Inertia::render('Procurement/PurchaseOrders/Edit', [
            'purchaseOrder' => $purchaseOrder,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
        ]);
    }

    /**
     * Update purchase order details.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseOrder->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft purchase orders can be edited.']
            ]);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|uuid',
            'branch_id' => 'required|uuid',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.ordered_quantity' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|gte:0',
        ]);

        // Verify supplier & branch belong to tenant
        Supplier::where('tenant_id', $tenantId)->active()->findOrFail($validated['supplier_id']);
        $branch = Branch::where('tenant_id', $tenantId)->active()->findOrFail($validated['branch_id']);
        if (!$user->canAccessBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        DB::transaction(function () use ($tenantId, $purchaseOrder, $validated) {
            // Delete old lines
            $purchaseOrder->lines()->delete();

            $totalAmount = 0.0000;

            foreach ($validated['lines'] as $lineData) {
                Product::where('tenant_id', $tenantId)->findOrFail($lineData['product_id']);

                $lineCost = (float) $lineData['unit_cost'];
                $lineQty = (float) $lineData['ordered_quantity'];
                $lineTotal = $lineQty * $lineCost;

                PurchaseOrderLine::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $lineData['product_id'],
                    'ordered_quantity' => $lineQty,
                    'unit_cost' => $lineCost,
                    'line_total' => $lineTotal,
                ]);

                $totalAmount += $lineTotal;
            }

            // Regenerate PO Number if branch or order date changed
            $poNumber = $purchaseOrder->po_number;
            if ($purchaseOrder->branch_id !== $validated['branch_id'] || $purchaseOrder->order_date->format('Y-m-d') !== $validated['order_date']) {
                $poNumber = PurchaseOrder::generatePoNumber($tenantId, $validated['branch_id'], $validated['order_date']);
            }

            $purchaseOrder->update([
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'po_number' => $poNumber,
                'total_estimated_amount' => $totalAmount,
            ]);
        });

        // Audit Log
        $this->auditLogger->log(
            action: 'purchase_order_updated',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number, 'amount' => $purchaseOrder->total_estimated_amount]
        );

        return redirect()->route('procurement.purchase-orders.show', $purchaseOrder->id)
            ->with('success', 'Purchase order updated successfully.');
    }

    /**
     * Submit PO draft for approval.
     */
    public function submit(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseOrder->canBeSubmitted()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft purchase orders can be submitted for approval.']
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_PENDING_APPROVAL,
        ]);

        $this->auditLogger->log(
            action: 'purchase_order_submitted',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number]
        );

        return back()->with('success', 'Purchase order submitted for approval.');
    }

    /**
     * Approve pending PO.
     */
    public function approve(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$user->hasPermission('procurement.purchase-orders.approve')) {
            abort(403, 'Unauthorized to approve purchase orders.');
        }

        if (!$purchaseOrder->canBeApproved()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending approval purchase orders can be approved.']
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'purchase_order_approved',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number]
        );

        return back()->with('success', 'Purchase order approved.');
    }

    /**
     * Send approved PO to supplier.
     */
    public function send(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseOrder->canBeSent()) {
            throw ValidationException::withMessages([
                'status' => ['Only approved purchase orders can be sent to suppliers.']
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'purchase_order_sent',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number]
        );

        return back()->with('success', 'Purchase order sent to supplier.');
    }

    /**
     * Mark PO as completed.
     */
    public function complete(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseOrder->canBeCompleted()) {
            throw ValidationException::withMessages([
                'status' => ['Only sent purchase orders can be completed.']
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'purchase_order_completed',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number]
        );

        return back()->with('success', 'Purchase order marked completed.');
    }

    /**
     * Cancel PO.
     */
    public function cancel(PurchaseOrder $purchaseOrder)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$purchaseOrder->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['Terminal purchase orders cannot be cancelled.']
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'purchase_order_cancelled',
            auditable: $purchaseOrder,
            metadata: ['po_number' => $purchaseOrder->po_number]
        );

        return back()->with('success', 'Purchase order has been cancelled.');
    }

    /**
     * Export purchase orders to CSV.
     */
    public function export(Request $request, \App\Services\Procurement\PurchaseOrderCsvExportService $exportService)
    {
        $user = auth()->user();
        $query = PurchaseOrder::query()->with(['supplier', 'branch', 'createdBy', 'approvedBy', 'lines.product']);

        // Branch Manager & Store Clerk scope restriction
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

        $purchaseOrders = $query->latest()->get();

        $csv = $exportService->export($purchaseOrders, $user);
        $filename = 'purchase_orders_export_' . now()->format('Ymd_His') . '.csv';

        // Audit Log
        $this->auditLogger->log(
            action: 'purchase_orders_exported',
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
     * Export a single purchase order to CSV.
     */
    public function exportOne(PurchaseOrder $purchaseOrder, \App\Services\Procurement\PurchaseOrderCsvExportService $exportService)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($purchaseOrder->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        $purchaseOrder->load(['lines.product', 'branch', 'supplier', 'createdBy', 'approvedBy']);

        $csv = $exportService->export(collect([$purchaseOrder]), $user);
        $filename = 'purchase_order_' . $purchaseOrder->po_number . '_' . now()->format('Ymd_His') . '.csv';

        // Audit Log
        $this->auditLogger->log(
            action: 'purchase_order_exported',
            auditable: $purchaseOrder,
            metadata: [
                'tenant_id' => app(TenantContext::class)->getTenantId(),
                'branch_id' => $purchaseOrder->branch_id,
                'record_id' => $purchaseOrder->id,
                'record_number' => $purchaseOrder->po_number,
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
