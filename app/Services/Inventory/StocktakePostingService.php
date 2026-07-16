<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class StocktakePostingService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected AuditLogger $auditLogger,
        protected InventoryMovementRecorder $movementRecorder,
        protected StocktakeReconciliationService $reconciliationService
    ) {}

    /**
     * Post a reviewed stocktake session.
     *
     * @param StocktakeSession $session
     * @return StocktakeSession
     * @throws ValidationException
     */
    public function post(StocktakeSession $session): StocktakeSession
    {
        if ($session->isPosted()) {
            $this->assertPostedEvidenceComplete($session);

            return $session;
        }

        // 1. Initial State Validation
        if (!$session->isInReview()) {
            throw new \RuntimeException("Only sessions in 'review' status can be posted. Current status: {$session->status}");
        }

        // 2. Data Integrity Validation
        $uncountedCount = $session->lines()->whereNull('counted_quantity')->count();
        if ($uncountedCount > 0) {
            throw ValidationException::withMessages([
                'session' => ["Cannot post. There are {$uncountedCount} uncounted items."]
            ]);
        }

        $missingReasons = $session->lines()
            ->whereRaw('ABS(COALESCE(variance_quantity, 0)) > 0.0001')
            ->whereNull('reason_code')
            ->count();
        if ($missingReasons > 0) {
            throw ValidationException::withMessages([
                'session' => ["Cannot post. {$missingReasons} items with variance are missing reason codes."]
            ]);
        }

        // Validate remarks for 'OTHER'
        $missingRemarks = $session->lines()
            ->where('reason_code', StocktakeLine::REASON_OTHER)
            ->whereNull('remarks')
            ->count();
        if ($missingRemarks > 0) {
            throw ValidationException::withMessages([
                'session' => ["Cannot post. Remarks are required for items with 'Other' reason code."]
            ]);
        }

        return DB::transaction(function () use ($session) {
            // 3. Row Locking for Double-Posting Protection
            $session = StocktakeSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
            
            if ($session->status === StocktakeSession::STATUS_POSTED) {
                $this->assertPostedEvidenceComplete($session);

                return $session;
            }

            if (!$session->isInReview()) {
                throw new \RuntimeException("Session status changed unexpectedly.");
            }

            $user = auth()->user();
            $postedAt = now();
            $lines = $session->lines()
                ->lockForUpdate()
                ->orderBy('product_id')
                ->get();

            $this->assertNoDuplicateProducts($lines);

            $inventories = BranchInventory::query()
                ->where('tenant_id', $session->tenant_id)
                ->where('branch_id', $session->branch_id)
                ->whereIn('product_id', $lines->pluck('product_id')->all())
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $lineResults = collect();
            $movementSequences = [];
            $movementCount = 0;

            foreach ($lines as $line) {
                if (!$inventories->has($line->product_id)) {
                    throw new \RuntimeException("missing_branch_inventory: stocktake_session_id={$session->id}; stocktake_line_id={$line->id}; product_id={$line->product_id}; branch_id={$session->branch_id}");
                }

                $result = $this->reconciliationService->reconcileLine($session, $line);

                if (abs((float) $result['posted_variance_quantity']) > 0.0001 && empty($line->reason_code)) {
                    throw ValidationException::withMessages([
                        'session' => ['Cannot post. Items with posted variance are missing reason codes.'],
                    ]);
                }

                $inventory = $inventories[$line->product_id];
                $postedVariance = (float) $result['posted_variance_quantity'];
                $quantityBefore = (float) $inventory->current_stock;
                $quantityAfter = round($quantityBefore + $postedVariance, 4);
                $revisionBefore = (int) ($inventory->inventory_revision ?? 1);
                $revisionAfter = $revisionBefore + (abs($postedVariance) > 0.0001 ? 1 : 0);
                $movement = null;

                if (abs($postedVariance) > 0.0001) {
                    $inventory->update([
                        'current_stock' => $this->reconciliationService->decimal($quantityAfter),
                        'inventory_revision' => $revisionAfter,
                        'last_counted_at' => $postedAt,
                    ]);

                    $movement = $this->movementRecorder->record($inventory->fresh(), [
                        'movement_type' => 'stock_correction',
                        'quantity_change' => $postedVariance,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'source_type' => 'stocktake_session',
                        'source_id' => $session->id,
                        'reference_number' => $session->stocktake_number,
                        'source_reference' => $session->stocktake_number,
                        'source_effect_key' => "stocktake:{$session->id}:line:{$line->id}:product:{$line->product_id}",
                        'user_id' => $user?->id,
                        'reason_code' => $line->reason_code,
                        'remarks' => $line->remarks,
                        'metadata' => $this->movementMetadata($session, $line, $result),
                    ]);

                    $movementSequences[] = $movement->movement_sequence;
                    $movementCount++;
                }

                $result['posting_inventory_revision_after'] = $revisionAfter;
                $result['posting_movement_id'] = $movement?->id;
                $result['posting_movement_sequence'] = $movement?->movement_sequence;
                $result['posting_snapshot'] = $this->movementMetadata($session, $line, $result);

                $line->update($this->lineEvidence($result));
                $lineResults->push($result);
            }

            $summary = $this->reconciliationService->summary($session, $lineResults);

            // 5. Finalize Session
            $session->update([
                'status' => StocktakeSession::STATUS_POSTED,
                'posted_by' => $user->id,
                'posted_at' => $postedAt,
                'posted_movement_sequence_min' => empty($movementSequences) ? null : min($movementSequences),
                'posted_movement_sequence_max' => empty($movementSequences) ? null : max($movementSequences),
                'posting_schema_version' => 1,
                'projection_policy_version' => StocktakeReconciliationService::PROJECTION_POLICY_VERSION,
                'posting_evidence_quality' => 'exact',
                'posting_summary_snapshot' => $summary,
                'session_revision' => ($session->session_revision ?? 1) + 1,
            ]);

            // 6. Audit Logging
            $this->auditLogger->log(
                action: 'stocktake_posted',
                auditable: $session,
                metadata: array_merge($summary, [
                    'stocktake_number' => $session->stocktake_number,
                    'line_count' => $lines->count(),
                    'movement_count' => $movementCount,
                    'posted_movement_sequence_min' => empty($movementSequences) ? null : min($movementSequences),
                    'posted_movement_sequence_max' => empty($movementSequences) ? null : max($movementSequences),
                    'posted_by' => $user?->name,
                ])
            );

            return $session;
        });
    }

    private function assertNoDuplicateProducts(Collection $lines): void
    {
        $duplicates = $lines->groupBy('product_id')->filter(fn (Collection $group) => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'session' => ['Cannot post. Duplicate product lines must be resolved before posting.'],
            ]);
        }
    }

    private function assertPostedEvidenceComplete(StocktakeSession $session): void
    {
        if (!$session->posted_at || $session->posting_evidence_quality !== 'exact') {
            throw new \RuntimeException('Posted stocktake evidence is incomplete.');
        }

        $session->loadMissing('lines');

        foreach ($session->lines as $line) {
            if ($line->posting_evidence_quality !== 'exact' || empty($line->posting_outcome)) {
                throw new \RuntimeException('Posted stocktake evidence is incomplete.');
            }
        }
    }

    private function lineEvidence(array $result): array
    {
        return [
            'movement_after_count_delta' => $result['movement_after_count_delta'],
            'movement_after_count_summary' => $result['movement_after_count_summary'],
            'movement_after_count_sequence_from' => $result['movement_after_count_sequence_from'],
            'movement_after_count_sequence_to' => $result['movement_after_count_sequence_to'],
            'movement_after_count_count' => $result['movement_after_count_count'],
            'expected_quantity_at_posting' => $result['expected_quantity_at_posting'],
            'posting_inventory_revision_before' => $result['posting_inventory_revision_before'],
            'posting_inventory_revision_after' => $result['posting_inventory_revision_after'],
            'counted_quantity_projected_to_posting' => $result['counted_quantity_projected_to_posting'],
            'posted_variance_quantity' => $result['posted_variance_quantity'],
            'posting_outcome' => $result['posting_outcome'],
            'projection_policy_version' => $result['projection_policy_version'],
            'posting_movement_id' => $result['posting_movement_id'],
            'posting_movement_sequence' => $result['posting_movement_sequence'],
            'posting_evidence_quality' => 'exact',
            'posting_snapshot' => $result['posting_snapshot'],
        ];
    }

    private function movementMetadata(StocktakeSession $session, StocktakeLine $line, array $result): array
    {
        return [
            'schema' => 'stocktake_posting_v1',
            'stocktake_session_id' => $session->id,
            'stocktake_line_id' => $line->id,
            'count_snapshot_uuid' => $line->count_snapshot_uuid,
            'stocktake_number' => $session->stocktake_number,
            'stocktake_operation_mode' => $session->stocktake_operation_mode ?? StocktakeSession::MODE_MOVEMENT_AWARE,
            'stocktake_scope_type' => $session->stocktake_scope_type ?? StocktakeSession::SCOPE_SELECTED_PRODUCTS,
            'projection_policy_version' => StocktakeReconciliationService::PROJECTION_POLICY_VERSION,
            'expected_quantity_at_count_start' => $result['expected_quantity_at_count_start'],
            'count_start_movement_sequence' => $result['count_start_movement_sequence'],
            'counted_quantity' => $result['counted_quantity'],
            'physically_counted_at' => $result['physically_counted_at'],
            'count_recorded_at' => $result['count_recorded_at'],
            'counted_movement_sequence' => $result['counted_movement_sequence'],
            'movement_during_count_delta' => $result['movement_during_count_delta'],
            'movement_during_count_summary' => $result['movement_during_count_summary'],
            'movement_during_count_sequence_from' => $result['movement_during_count_sequence_from'],
            'movement_during_count_sequence_to' => $result['movement_during_count_sequence_to'],
            'movement_during_count_count' => $result['movement_during_count_count'],
            'movement_after_count_delta' => $result['movement_after_count_delta'],
            'movement_after_count_summary' => $result['movement_after_count_summary'],
            'movement_after_count_sequence_from' => $result['movement_after_count_sequence_from'],
            'movement_after_count_sequence_to' => $result['movement_after_count_sequence_to'],
            'movement_after_count_count' => $result['movement_after_count_count'],
            'expected_quantity_at_posting' => $result['expected_quantity_at_posting'],
            'counted_quantity_projected_to_posting' => $result['counted_quantity_projected_to_posting'],
            'posted_variance_quantity' => $result['posted_variance_quantity'],
            'posting_outcome' => $result['posting_outcome'],
            'reason_code' => $line->reason_code,
            'reason_schema_version' => $line->reason_schema_version ?? 1,
        ];
    }
}
