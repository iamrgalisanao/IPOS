<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\PurchaseReceiving;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\Procurement\SupplierReturnPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SupplierReturnController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected SupplierReturnPostingService $postingService
    ) {}

    /**
     * Display a listing of the supplier returns.
     */
    public function index(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        $query = SupplierReturn::query()->with(['supplier', 'branch', 'createdBy']);

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

        $supplierReturns = $query->latest()->get();

        // Extra details for listing filters and selectors
        $suppliers = Supplier::active()->get();

        $branchesQuery = Branch::active();
        if (!$user->hasPermission('view_multi_branch_dashboard')) {
            $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $branchesQuery->whereIn('id', $assignedBranchIds);
        }
        $branches = $branchesQuery->get();

        return Inertia::render('Procurement/Returns/Index', [
            'supplierReturns' => $supplierReturns,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'filters' => $request->only(['branch_id', 'status', 'supplier_id']),
        ]);
    }

    /**
     * Show the form for creating a new supplier return.
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

        // Get list of active products
        $products = Product::where('tenant_id', $tenantId)->get(['id', 'name', 'sku', 'cost_price']);

        // Prefill option from posted Purchase Receiving voucher
        $purchaseReceiving = null;
        if ($request->filled('purchase_receiving_id')) {
            $purchaseReceiving = PurchaseReceiving::where('tenant_id', $tenantId)
                ->with(['lines.product', 'supplier', 'branch'])
                ->findOrFail($request->input('purchase_receiving_id'));

            // Check if PR is posted
            if (!$purchaseReceiving->isPosted()) {
                return redirect()->route('procurement.receivings.show', $purchaseReceiving->id)
                    ->with('error', 'Only posted goods receiving vouchers can be used for returns.');
            }

            // Check branch access
            if (!$user->canAccessBranch($purchaseReceiving->branch)) {
                abort(403, 'Unauthorized access to purchase receiving branch.');
            }
        }

        return Inertia::render('Procurement/Returns/Create', [
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
            'purchaseReceiving' => $purchaseReceiving,
        ]);
    }

    /**
     * Store a newly created supplier return draft.
     */
    public function store(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        $validated = $request->validate([
            'supplier_id' => 'required|uuid',
            'branch_id' => 'required|uuid',
            'purchase_receiving_id' => 'nullable|uuid',
            'return_date' => 'required|date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.expiry_lot_id' => 'nullable|uuid',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|gte:0',
        ]);

        $supplier = Supplier::where('tenant_id', $tenantId)->active()->findOrFail($validated['supplier_id']);
        $branch = Branch::where('tenant_id', $tenantId)->active()->findOrFail($validated['branch_id']);

        if (!$user->canAccessBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        if (!empty($validated['purchase_receiving_id'])) {
            $receiving = PurchaseReceiving::where('tenant_id', $tenantId)->findOrFail($validated['purchase_receiving_id']);
            
            if (!$receiving->isPosted()) {
                throw ValidationException::withMessages([
                    'purchase_receiving_id' => ['The loaded receiving voucher must be posted.']
                ]);
            }

            if ($receiving->branch_id !== $validated['branch_id']) {
                throw ValidationException::withMessages([
                    'purchase_receiving_id' => ['The receiving branch does not match the return branch.']
                ]);
            }

            if ($receiving->supplier_id !== $validated['supplier_id']) {
                throw ValidationException::withMessages([
                    'purchase_receiving_id' => ['The receiving supplier does not match the return supplier.']
                ]);
            }
        }

        $supplierReturn = DB::transaction(function () use ($tenantId, $validated) {
            $documentNumber = SupplierReturn::generateDocumentNumber($tenantId, $validated['branch_id'], $validated['return_date']);

            $sr = SupplierReturn::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'purchase_receiving_id' => $validated['purchase_receiving_id'] ?? null,
                'document_number' => $documentNumber,
                'status' => SupplierReturn::STATUS_DRAFT,
                'return_date' => $validated['return_date'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
                'total_amount' => 0.0000,
            ]);

            $totalAmount = 0.0000;

            foreach ($validated['lines'] as $lineData) {
                Product::where('tenant_id', $tenantId)->findOrFail($lineData['product_id']);

                $qty = (float) $lineData['quantity'];
                $cost = (float) $lineData['unit_cost'];
                $lineTotal = $qty * $cost;

                SupplierReturnLine::create([
                    'supplier_return_id' => $sr->id,
                    'product_id' => $lineData['product_id'],
                    'expiry_lot_id' => $lineData['expiry_lot_id'] ?? null,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                ]);

                $totalAmount += $lineTotal;
            }

            $sr->update(['total_amount' => $totalAmount]);

            return $sr;
        });

        $this->auditLogger->log(
            action: 'supplier_return_created',
            auditable: $supplierReturn,
            metadata: ['document_number' => $supplierReturn->document_number, 'amount' => $supplierReturn->total_amount]
        );

        return redirect()->route('procurement.returns.show', $supplierReturn->id)
            ->with('success', 'Supplier return draft created.');
    }

    /**
     * Display supplier return details.
     */
    public function show(SupplierReturn $supplierReturn)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        $supplierReturn->load(['supplier', 'branch', 'purchaseReceiving', 'createdBy', 'approvedBy', 'postedBy', 'lines.product', 'lines.expiryLot']);

        return Inertia::render('Procurement/Returns/Show', [
            'supplierReturn' => $supplierReturn,
        ]);
    }

    /**
     * Show form for editing a draft.
     */
    public function edit(SupplierReturn $supplierReturn)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$supplierReturn->canBeEdited()) {
            return redirect()->route('procurement.returns.show', $supplierReturn->id)
                ->with('error', 'Only draft supplier returns can be edited.');
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

        $supplierReturn->load('lines');

        return Inertia::render('Procurement/Returns/Edit', [
            'supplierReturn' => $supplierReturn,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
        ]);
    }

    /**
     * Update supplier return details.
     */
    public function update(Request $request, SupplierReturn $supplierReturn)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();

        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$supplierReturn->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft supplier returns can be edited.']
            ]);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|uuid',
            'branch_id' => 'required|uuid',
            'purchase_receiving_id' => 'nullable|uuid',
            'return_date' => 'required|date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.expiry_lot_id' => 'nullable|uuid',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|gte:0',
        ]);

        Supplier::where('tenant_id', $tenantId)->active()->findOrFail($validated['supplier_id']);
        $branch = Branch::where('tenant_id', $tenantId)->active()->findOrFail($validated['branch_id']);
        if (!$user->canAccessBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        if (!empty($validated['purchase_receiving_id'])) {
            $receiving = PurchaseReceiving::where('tenant_id', $tenantId)->findOrFail($validated['purchase_receiving_id']);
            if (!$receiving->isPosted()) {
                throw ValidationException::withMessages([
                    'purchase_receiving_id' => ['The loaded receiving voucher must be posted.']
                ]);
            }
            if ($receiving->branch_id !== $validated['branch_id']) {
                throw ValidationException::withMessages([
                    'purchase_receiving_id' => ['The receiving branch does not match the return branch.']
                ]);
            }
            if ($receiving->supplier_id !== $validated['supplier_id']) {
                throw ValidationException::withMessages([
                    'purchase_receiving_id' => ['The receiving supplier does not match the return supplier.']
                ]);
            }
        }

        DB::transaction(function () use ($tenantId, $supplierReturn, $validated) {
            $supplierReturn->lines()->delete();

            $totalAmount = 0.0000;

            foreach ($validated['lines'] as $lineData) {
                Product::where('tenant_id', $tenantId)->findOrFail($lineData['product_id']);

                $qty = (float) $lineData['quantity'];
                $cost = (float) $lineData['unit_cost'];
                $lineTotal = $qty * $cost;

                SupplierReturnLine::create([
                    'supplier_return_id' => $supplierReturn->id,
                    'product_id' => $lineData['product_id'],
                    'expiry_lot_id' => $lineData['expiry_lot_id'] ?? null,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                ]);

                $totalAmount += $lineTotal;
            }

            $documentNumber = $supplierReturn->document_number;
            $oldDate = $supplierReturn->return_date ? $supplierReturn->return_date->format('Y-m-d') : null;
            if ($supplierReturn->branch_id !== $validated['branch_id'] || $oldDate !== $validated['return_date']) {
                $documentNumber = SupplierReturn::generateDocumentNumber($tenantId, $validated['branch_id'], $validated['return_date']);
            }

            $supplierReturn->update([
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'purchase_receiving_id' => $validated['purchase_receiving_id'] ?? null,
                'return_date' => $validated['return_date'],
                'notes' => $validated['notes'] ?? null,
                'document_number' => $documentNumber,
                'total_amount' => $totalAmount,
            ]);
        });

        $this->auditLogger->log(
            action: 'supplier_return_updated',
            auditable: $supplierReturn,
            metadata: ['document_number' => $supplierReturn->document_number, 'amount' => $supplierReturn->total_amount]
        );

        return redirect()->route('procurement.returns.show', $supplierReturn->id)
            ->with('success', 'Supplier return updated successfully.');
    }

    /**
     * Submit supplier return draft for approval.
     */
    public function submit(SupplierReturn $supplierReturn)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$supplierReturn->canBeSubmitted()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft supplier returns can be submitted for approval.']
            ]);
        }

        $supplierReturn->update([
            'status' => SupplierReturn::STATUS_PENDING_APPROVAL,
        ]);

        $this->auditLogger->log(
            action: 'supplier_return_submitted',
            auditable: $supplierReturn,
            metadata: ['document_number' => $supplierReturn->document_number]
        );

        return back()->with('success', 'Supplier return submitted for approval.');
    }

    /**
     * Approve pending supplier return.
     */
    public function approve(SupplierReturn $supplierReturn)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$user->hasPermission('procurement.returns.approve')) {
            abort(403, 'Unauthorized to approve supplier returns.');
        }

        if (!$supplierReturn->canBeApproved()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending approval supplier returns can be approved.']
            ]);
        }

        $supplierReturn->update([
            'status' => SupplierReturn::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'supplier_return_approved',
            auditable: $supplierReturn,
            metadata: ['document_number' => $supplierReturn->document_number]
        );

        return back()->with('success', 'Supplier return approved.');
    }

    /**
     * Cancel supplier return.
     */
    public function cancel(SupplierReturn $supplierReturn)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$supplierReturn->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['Terminal or non-cancellable supplier returns cannot be cancelled.']
            ]);
        }

        $supplierReturn->update([
            'status' => SupplierReturn::STATUS_CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'supplier_return_cancelled',
            auditable: $supplierReturn,
            metadata: ['document_number' => $supplierReturn->document_number]
        );

        return back()->with('success', 'Supplier return has been cancelled.');
    }

    /**
     * Post approved supplier return.
     */
    public function post(SupplierReturn $supplierReturn)
    {
        $user = auth()->user();
        if (!$user->canAccessBranch($supplierReturn->branch)) {
            abort(403, 'Unauthorized access to branch.');
        }

        if (!$user->hasPermission('procurement.returns.post')) {
              abort(403, 'Unauthorized to post supplier returns.');
        }

        if (!$supplierReturn->canBePosted()) {
            throw ValidationException::withMessages([
                'status' => ['Only approved supplier returns can be posted.']
            ]);
        }

        try {
            $this->postingService->post($supplierReturn, auth()->id());
        } catch (\Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            throw ValidationException::withMessages([
                'status' => [$e->getMessage()]
            ]);
        }

        return back()->with('success', 'Supplier return posted successfully.');
    }
}
