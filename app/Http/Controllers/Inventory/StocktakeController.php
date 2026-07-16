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
use Illuminate\Support\Str;
use Inertia\Inertia;

use App\Services\Inventory\StocktakePostingService;
use App\Services\Inventory\StocktakeReconciliationService;
use App\Services\AuditLogger;

class StocktakeController extends Controller
{
    public function __construct(
        protected StocktakePostingService $postingService,
        protected StocktakeReconciliationService $reconciliationService,
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
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'stocktake_operation_mode' => 'nullable|string|in:' . StocktakeSession::MODE_MOVEMENT_AWARE,
            'stocktake_scope_type' => 'nullable|string|in:' . implode(',', [
                StocktakeSession::SCOPE_SELECTED_PRODUCTS,
                StocktakeSession::SCOPE_FULL_BRANCH,
            ]),
        ]);

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
            'stocktake_operation_mode' => $validated['stocktake_operation_mode'] ?? StocktakeSession::MODE_MOVEMENT_AWARE,
            'stocktake_scope_type' => $validated['stocktake_scope_type'] ?? StocktakeSession::SCOPE_SELECTED_PRODUCTS,
            'posting_evidence_quality' => 'legacy',
            'notes' => $validated['notes'] ?? null,
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
                'physically_counted_at' => $line->physically_counted_at,
                'count_snapshot_uuid' => $line->count_snapshot_uuid,
            ];

            if ($canReview) {
                $data['expected_quantity'] = $line->expected_quantity;
                $data['expected_quantity_at_count_start'] = $line->expected_quantity_at_count_start;
                $data['expected_quantity_at_count_time'] = $line->expected_quantity_at_count_time;
                $data['variance_quantity'] = $line->variance_quantity;
                $data['physical_count_variance_quantity'] = $line->physical_count_variance_quantity;
                $data['movement_during_count_delta'] = $line->movement_during_count_delta;
                $data['movement_after_count_delta'] = $line->movement_after_count_delta;
                $data['posted_variance_quantity'] = $line->posted_variance_quantity;
                $data['posting_outcome'] = $line->posting_outcome;
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
            $startedAt = now();

            // Lock the branch stock rows before taking the movement watermark so the
            // full-branch snapshot and sequence evidence are captured together.
            $inventories = BranchInventory::query()
                ->where('tenant_id', $stocktakeSession->tenant_id)
                ->where('branch_id', $stocktakeSession->branch_id)
                ->where('status', 'active')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            $watermark = $this->reconciliationService->latestBranchSequence($stocktakeSession->tenant_id, $stocktakeSession->branch_id);

            $stocktakeSession->update([
                'status' => StocktakeSession::STATUS_COUNTING,
                'started_at' => $startedAt,
                'count_started_at' => $startedAt,
                'count_start_movement_sequence' => $watermark,
                'stocktake_operation_mode' => $stocktakeSession->stocktake_operation_mode ?: StocktakeSession::MODE_MOVEMENT_AWARE,
                'stocktake_scope_type' => StocktakeSession::SCOPE_FULL_BRANCH,
                'session_revision' => ($stocktakeSession->session_revision ?? 1) + 1,
            ]);

            foreach ($inventories as $inventory) {
                $line = StocktakeLine::create([
                    'tenant_id' => $stocktakeSession->tenant_id,
                    'branch_id' => $stocktakeSession->branch_id,
                    'stocktake_session_id' => $stocktakeSession->id,
                    'product_id' => $inventory->product_id,
                    'expected_quantity' => $inventory->current_stock,
                    'counted_quantity' => null,
                    'variance_quantity' => null,
                ]);

                $this->reconciliationService->initializeLineSnapshot($line, $inventory, $watermark, $startedAt);
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
            'lines.*.physically_counted_at' => 'nullable|date',
        ]);

        DB::transaction(function () use ($validated, $stocktakeSession) {
            foreach ($validated['lines'] as $lineData) {
                $line = StocktakeLine::findOrFail($lineData['id']);
                
                // Security check
                if ($line->stocktake_session_id !== $stocktakeSession->id) continue;

                $inventory = BranchInventory::where('tenant_id', $stocktakeSession->tenant_id)
                    ->where('branch_id', $stocktakeSession->branch_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $countSnapshot = $this->reconciliationService->acceptCountSnapshot(
                        $line,
                        $inventory,
                        $lineData['counted_quantity'] === null ? null : (float) $lineData['counted_quantity'],
                        isset($lineData['physically_counted_at']) ? \Illuminate\Support\Carbon::parse($lineData['physically_counted_at']) : null
                    );
                } else {
                    $countedQuantity = $lineData['counted_quantity'] === null ? null : (float) $lineData['counted_quantity'];
                    $expectedQuantity = (float) ($line->expected_quantity_at_count_start ?? $line->expected_quantity ?? 0);
                    $variance = $countedQuantity === null ? null : $countedQuantity - $expectedQuantity;

                    $countSnapshot = [
                        'counted_quantity' => $countedQuantity,
                        'variance_quantity' => $variance,
                        'raw_count_start_difference' => $variance,
                        'count_snapshot_uuid' => $countedQuantity === null ? null : (string) Str::orderedUuid(),
                        'count_snapshot_schema_version' => 1,
                        'physically_counted_at' => $countedQuantity === null ? null : now(),
                        'count_recorded_at' => $countedQuantity === null ? null : now(),
                        'counted_inventory_revision' => null,
                        'counted_movement_sequence' => $this->reconciliationService->latestBranchSequence($stocktakeSession->tenant_id, $stocktakeSession->branch_id),
                        'expected_quantity_at_count_time' => $expectedQuantity,
                        'physical_count_variance_quantity' => $variance,
                        'movement_during_count_delta' => '0.0000',
                        'movement_during_count_summary' => null,
                        'movement_during_count_sequence_from' => null,
                        'movement_during_count_sequence_to' => null,
                        'movement_during_count_count' => 0,
                    ];
                }

                $updateData = array_merge($countSnapshot, [
                    'remarks' => $lineData['remarks'] ?? null,
                ]);

                if ($lineData['counted_quantity'] !== null) {
                    $updateData['counted_by'] = auth()->id();
                    $updateData['counted_at'] = now();
                } else {
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

    public function postingPreview(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        $user = auth()->user();
        if (!$user->hasPermission('inventory.stocktake.post')) {
            abort(403, 'Unauthorized to preview stocktake posting.');
        }

        if (!$stocktakeSession->isInReview()) {
            return response()->json(['error' => 'Session must be in review state to preview posting.'], 409);
        }

        try {
            return response()->json($this->reconciliationService->preview($stocktakeSession));
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }
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

    public function post(Request $request, StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        $user = auth()->user();
        if (!$user->hasPermission('inventory.stocktake.post')) {
            abort(403, 'Unauthorized to post stocktake sessions.');
        }

        try {
            $wasAlreadyPosted = $stocktakeSession->isPosted();

            if (!$request->boolean('post_using_latest_movement_state') && $request->filled('preview_latest_movement_sequence')) {
                $latestSequence = $this->reconciliationService->latestBranchSequence(
                    $stocktakeSession->tenant_id,
                    $stocktakeSession->branch_id
                );

                if ((int) $request->input('preview_latest_movement_sequence') !== $latestSequence) {
                    return back()->with('error', 'STOCKTAKE_PREVIEW_STALE');
                }
            }

            $this->postingService->post($stocktakeSession);

            $message = $wasAlreadyPosted
                ? 'Stocktake was already posted. No inventory changes were made.'
                : 'Stocktake posted successfully. Inventory has been updated.';

            return redirect()->route('inventory.stocktakes.index')->with('success', $message);
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
            $inventory = BranchInventory::where('tenant_id', $stocktakeSession->tenant_id)
                ->where('branch_id', $stocktakeSession->branch_id)
                ->where('product_id', $productId)
                ->firstOrFail();

            // 2. Add Stocktake Line
            $line = StocktakeLine::create([
                'tenant_id' => $stocktakeSession->tenant_id,
                'branch_id' => $stocktakeSession->branch_id,
                'stocktake_session_id' => $stocktakeSession->id,
                'product_id' => $productId,
                'expected_quantity' => $inventory->current_stock,
                'counted_quantity' => null,
                'variance_quantity' => null,
            ]);

            $this->reconciliationService->initializeLineSnapshot(
                $line,
                $inventory,
                $this->reconciliationService->latestBranchSequence($stocktakeSession->tenant_id, $stocktakeSession->branch_id),
                now()
            );
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
