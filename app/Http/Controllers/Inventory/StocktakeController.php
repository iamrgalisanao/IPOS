<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

use App\Services\Inventory\StocktakePostingService;
use App\Services\AuditLogger;

class StocktakeController extends Controller
{
    public function __construct(
        protected StocktakePostingService $postingService,
        protected AuditLogger $auditLogger
    ) {}
    public function index()
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $branchId = app(BranchContext::class)->getBranchId();

        $sessions = StocktakeSession::query()
            ->with(['startedByUser'])
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->latest()
            ->paginate(15);

        return Inertia::render('Inventory/Stocktake/Index', [
            'sessions' => $sessions,
        ]);
    }

    public function create()
    {
        return Inertia::render('Inventory/Stocktake/Create');
    }

    public function store(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $branchId = app(BranchContext::class)->getBranchId();
        $userId = auth()->id();

        $stocktakeNumber = 'ST-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $session = StocktakeSession::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'stocktake_number' => $stocktakeNumber,
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $userId,
            'notes' => $request->input('notes'),
        ]);

        return redirect()->route('inventory.stocktakes.show', $session->id)
            ->with('success', 'Draft stocktake session created.');
    }

    public function show(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        $stocktakeSession->load(['startedByUser', 'reviewedByUser', 'approvedByUser', 'postedByUser']);

        $linesQuery = $stocktakeSession->lines()
            ->with(['product'])
            ->orderBy('created_at');

        $user = auth()->user();
        $canReview = $user->hasPermission('inventory.stocktake.review') || 
                     $user->hasPermission('inventory.stocktake.approve') ||
                     $user->hasPermission('inventory.stocktake.post');

        // Blind Count Logic: Only include expected_quantity and variance if authorized
        $lines = $linesQuery->get()->map(function (StocktakeLine $line) use ($canReview) {
            $data = [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product->name,
                'sku' => $line->product->sku,
                'counted_quantity' => $line->counted_quantity,
                'remarks' => $line->remarks,
                'counted_at' => $line->counted_at,
            ];

            if ($canReview) {
                $data['expected_quantity'] = $line->expected_quantity;
                $data['variance_quantity'] = $line->variance_quantity;
                $data['reason_code'] = $line->reason_code;
            }

            return $data;
        });

        $view = 'Inventory/Stocktake/Show';
        if ($stocktakeSession->isInReview() && $user->hasPermission('inventory.stocktake.review')) {
            $view = 'Inventory/Stocktake/Review';
        }

        return Inertia::render($view, [
            'session' => $stocktakeSession,
            'lines' => $lines,
            'isBlindCount' => !$canReview,
            'reasonCodes' => StocktakeLine::getReasonCodes(),
        ]);
    }

    public function startCounting(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        if (!$stocktakeSession->isDraft()) {
            return back()->with('error', 'Only draft sessions can be moved to counting.');
        }

        DB::transaction(function () use ($stocktakeSession) {
            $stocktakeSession->update([
                'status' => StocktakeSession::STATUS_COUNTING,
                'started_at' => now(),
            ]);

            // Snapshot all active products in this branch
            $inventories = BranchInventory::query()
                ->where('branch_id', $stocktakeSession->branch_id)
                ->where('status', 'active')
                ->get();

            foreach ($inventories as $inventory) {
                StocktakeLine::create([
                    'tenant_id' => $stocktakeSession->tenant_id,
                    'branch_id' => $stocktakeSession->branch_id,
                    'stocktake_session_id' => $stocktakeSession->id,
                    'product_id' => $inventory->product_id,
                    'expected_quantity' => $inventory->current_stock,
                    'counted_quantity' => null,
                    'variance_quantity' => null,
                ]);
            }
        });

        $this->auditLogger->log(
            action: 'stocktake_counting_started',
            auditable: $stocktakeSession,
            metadata: ['stocktake_number' => $stocktakeSession->stocktake_number]
        );

        return back()->with('success', 'Stocktake counting started.');
    }

    public function updateLines(Request $request, StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        if (!$stocktakeSession->isCounting()) {
            return response()->json(['error' => 'Session is not in counting state.'], 422);
        }

        if ($stocktakeSession->isTerminal()) {
            return response()->json(['error' => 'Terminal sessions cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.id' => 'required|uuid|exists:stocktake_lines,id',
            'lines.*.counted_quantity' => 'nullable|numeric|min:0',
            'lines.*.remarks' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $stocktakeSession) {
            foreach ($validated['lines'] as $lineData) {
                $line = StocktakeLine::findOrFail($lineData['id']);
                
                // Security check
                if ($line->stocktake_session_id !== $stocktakeSession->id) continue;

                $updateData = [
                    'counted_quantity' => $lineData['counted_quantity'],
                    'remarks' => $lineData['remarks'] ?? null,
                ];

                if ($lineData['counted_quantity'] !== null) {
                    $updateData['variance_quantity'] = $lineData['counted_quantity'] - $line->expected_quantity;
                    $updateData['counted_by'] = auth()->id();
                    $updateData['counted_at'] = now();
                } else {
                    $updateData['variance_quantity'] = null;
                    $updateData['counted_by'] = null;
                    $updateData['counted_at'] = null;
                }

                $line->update($updateData);
            }
        });

        return back()->with('success', 'Progress saved.');
    }

    public function submitForReview(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        if (!$stocktakeSession->isCounting()) {
            return back()->with('error', 'Only active counting sessions can be submitted for review.');
        }

        // Validate all lines are counted
        $uncountedCount = $stocktakeSession->lines()->whereNull('counted_quantity')->count();
        if ($uncountedCount > 0) {
            return back()->with('error', "Cannot submit for review. There are {$uncountedCount} uncounted items.");
        }

        $stocktakeSession->update([
            'status' => StocktakeSession::STATUS_REVIEW,
            'submitted_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'stocktake_submitted_for_review',
            auditable: $stocktakeSession,
            metadata: ['stocktake_number' => $stocktakeSession->stocktake_number]
        );

        return back()->with('success', 'Stocktake submitted for review.');
    }

    public function updateVarianceReasons(Request $request, StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        if (!$stocktakeSession->isInReview()) {
            return back()->with('error', 'Session must be in review state to update reasons.');
        }

        if ($stocktakeSession->isTerminal()) {
            return back()->with('error', 'Terminal sessions cannot be edited.');
        }

        $user = auth()->user();
        if (!$user->hasPermission('inventory.stocktake.review')) {
            abort(403, 'Unauthorized to update variance reasons.');
        }

        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.id' => 'required|uuid|exists:stocktake_lines,id',
            'lines.*.reason_code' => 'nullable|string|in:' . implode(',', array_keys(StocktakeLine::getReasonCodes())),
            'lines.*.remarks' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $stocktakeSession) {
            foreach ($validated['lines'] as $lineData) {
                $line = StocktakeLine::findOrFail($lineData['id']);
                if ($line->stocktake_session_id !== $stocktakeSession->id) continue;

                // Validation: OTHER reason requires remarks
                if ($lineData['reason_code'] === StocktakeLine::REASON_OTHER && empty($lineData['remarks'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'lines.' . $lineData['id'] . '.remarks' => ["Remarks are required for 'Other' reason code."]
                    ]);
                }

                $line->update([
                    'reason_code' => $lineData['reason_code'],
                    'remarks' => $lineData['remarks'],
                ]);
            }
        });

        return back()->with('success', 'Variance reasons updated.');
    }

    public function reject(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        if (!$stocktakeSession->isInReview()) {
            return back()->with('error', 'Only sessions in review can be rejected.');
        }

        $user = auth()->user();
        if (!$user->hasPermission('inventory.stocktake.review')) {
            abort(403, 'Unauthorized to reject sessions.');
        }

        $stocktakeSession->update([
            'status' => StocktakeSession::STATUS_REJECTED,
            'rejected_at' => now(),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'stocktake_rejected',
            auditable: $stocktakeSession,
            metadata: ['stocktake_number' => $stocktakeSession->stocktake_number]
        );

        return redirect()->route('inventory.stocktakes.index')
            ->with('success', 'Stocktake session has been rejected.');
    }

    public function cancel(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        $user = auth()->user();
        if (!$user->hasPermission('inventory.stocktake.cancel')) {
            abort(403, 'Unauthorized to cancel stocktake sessions.');
        }

        if ($stocktakeSession->isTerminal()) {
            return back()->with('error', 'Terminal sessions cannot be cancelled.');
        }

        $stocktakeSession->update([
            'status' => StocktakeSession::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'stocktake_cancelled',
            auditable: $stocktakeSession,
            metadata: ['stocktake_number' => $stocktakeSession->stocktake_number]
        );

        return redirect()->route('inventory.stocktakes.index')
            ->with('success', 'Stocktake session has been cancelled.');
    }

    public function post(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        $user = auth()->user();
        if (!$user->hasPermission('inventory.stocktake.post')) {
            abort(403, 'Unauthorized to post stocktake sessions.');
        }

        try {
            $this->postingService->post($stocktakeSession);
            return redirect()->route('inventory.stocktakes.index')
                ->with('success', 'Stocktake posted successfully. Inventory has been updated.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Search the master catalog for products that can be added to the session.
     */
    public function searchCatalog(Request $request)
    {
        $search = $request->query('q');
        if (strlen($search) < 2) return response()->json([]);

        $tenantId = app(TenantContext::class)->getTenantId();

        $products = Product::where('tenant_id', $tenantId)
            ->where('is_inventory_tracked', true)
            ->where(function ($query) use ($search) {
                $query->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('sku', 'ILIKE', "%{$search}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'sku']);

        return response()->json($products);
    }

    /**
     * Add a product to an active stocktake session.
     */
    public function addLine(Request $request, StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        if (!$stocktakeSession->isCounting()) {
            return back()->with('error', 'Products can only be added to active counting sessions.');
        }

        $validated = $request->validate([
            'product_id' => 'required|uuid|exists:products,id',
        ]);

        $productId = $validated['product_id'];

        // Check if already in session
        if ($stocktakeSession->lines()->where('product_id', $productId)->exists()) {
            return back()->with('error', 'Product is already in this session.');
        }

        DB::transaction(function () use ($stocktakeSession, $productId) {
            // 1. Ensure BranchInventory exists (or at least get current stock)
            $inventory = BranchInventory::firstOrCreate(
                [
                    'tenant_id' => $stocktakeSession->tenant_id,
                    'branch_id' => $stocktakeSession->branch_id,
                    'product_id' => $productId,
                ],
                [
                    'current_stock' => 0,
                    'status' => 'active',
                ]
            );

            // 2. Add Stocktake Line
            StocktakeLine::create([
                'tenant_id' => $stocktakeSession->tenant_id,
                'branch_id' => $stocktakeSession->branch_id,
                'stocktake_session_id' => $stocktakeSession->id,
                'product_id' => $productId,
                'expected_quantity' => $inventory->current_stock,
                'counted_quantity' => null,
                'variance_quantity' => null,
            ]);
        });

        return back()->with('success', 'Product added to session.');
    }

    /**
     * Internal helper to enforce branch/tenant isolation
     */
    protected function authorizeAccess(StocktakeSession $session)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $branchId = app(BranchContext::class)->getBranchId();

        if ($session->tenant_id !== $tenantId || $session->branch_id !== $branchId) {
            abort(403, 'Unauthorized access to stocktake session.');
        }
    }
}
