<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StocktakeReconciliationService
{
    public const PROJECTION_POLICY_VERSION = 1;

    private const ELIGIBLE_MOVEMENT_TYPES = [
        'sale_deduction',
        'stock_in',
        'void_reversal',
        'refund_return',
        'stock_correction',
        'manual_adjustment',
        'supplier_receiving',
        'supplier_return',
        'ibt_dispatch',
        'ibt_receipt',
    ];

    public function latestBranchSequence(string $tenantId, string $branchId): int
    {
        return (int) InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->max('movement_sequence');
    }

    public function initializeLineSnapshot(
        StocktakeLine $line,
        BranchInventory $inventory,
        ?int $watermark = null,
        ?Carbon $capturedAt = null
    ): StocktakeLine {
        $capturedAt ??= now();
        $watermark ??= $this->latestBranchSequence($line->tenant_id, $line->branch_id);

        $line->fill([
            'expected_quantity' => $this->decimal($inventory->current_stock),
            'expected_quantity_at_count_start' => $this->decimal($inventory->current_stock),
            'count_start_movement_sequence' => $watermark,
            'count_start_stock_snapshot_at' => $capturedAt,
        ])->save();

        return $line;
    }

    public function acceptCountSnapshot(
        StocktakeLine $line,
        BranchInventory $inventory,
        ?float $countedQuantity,
        ?Carbon $physicallyCountedAt = null
    ): array {
        if ($countedQuantity === null) {
            return [
                'counted_quantity' => null,
                'variance_quantity' => null,
                'raw_count_start_difference' => null,
                'count_snapshot_uuid' => null,
                'physically_counted_at' => null,
                'count_recorded_at' => null,
                'counted_inventory_revision' => null,
                'counted_movement_sequence' => null,
                'expected_quantity_at_count_time' => null,
                'physical_count_variance_quantity' => null,
                'movement_during_count_delta' => null,
                'movement_during_count_summary' => null,
                'movement_during_count_sequence_from' => null,
                'movement_during_count_sequence_to' => null,
                'movement_during_count_count' => null,
            ];
        }

        $recordedAt = now();
        $physicallyCountedAt ??= $recordedAt;
        $countedSequence = $this->latestBranchSequence($line->tenant_id, $line->branch_id);
        $countStartSequence = $line->count_start_movement_sequence ?? 0;
        $movementDuring = $this->movementEvidence(
            $line->session,
            $line->product_id,
            $countStartSequence,
            $countedSequence
        );

        $expectedAtCountTime = (float) $inventory->current_stock;
        $rawDifference = $countedQuantity - (float) ($line->expected_quantity_at_count_start ?? $line->expected_quantity ?? 0);
        $physicalVariance = $countedQuantity - $expectedAtCountTime;

        return [
            'counted_quantity' => $this->decimal($countedQuantity),
            'variance_quantity' => $this->decimal($physicalVariance),
            'raw_count_start_difference' => $this->decimal($rawDifference),
            'count_snapshot_uuid' => (string) Str::orderedUuid(),
            'count_snapshot_schema_version' => 1,
            'physically_counted_at' => $physicallyCountedAt,
            'count_recorded_at' => $recordedAt,
            'counted_inventory_revision' => $inventory->inventory_revision ?? 1,
            'counted_movement_sequence' => $countedSequence,
            'expected_quantity_at_count_time' => $this->decimal($expectedAtCountTime),
            'physical_count_variance_quantity' => $this->decimal($physicalVariance),
            'movement_during_count_delta' => $movementDuring['delta'],
            'movement_during_count_summary' => $movementDuring['summary'],
            'movement_during_count_sequence_from' => $movementDuring['sequence_from'],
            'movement_during_count_sequence_to' => $movementDuring['sequence_to'],
            'movement_during_count_count' => $movementDuring['count'],
        ];
    }

    public function preview(StocktakeSession $session): array
    {
        $lines = $session->lines()->with(['product'])->orderBy('created_at')->get();
        $lineResults = $lines->map(fn (StocktakeLine $line) => $this->reconcileLine($session, $line));
        $summary = $this->summary($session, $lineResults);

        $generatedAt = now();
        $latestSequence = $this->latestBranchSequence($session->tenant_id, $session->branch_id);
        $inventoryRevision = $lineResults->max('posting_inventory_revision_before');

        return [
            'preview_generated_at' => $generatedAt->toISOString(),
            'preview_latest_movement_sequence' => $latestSequence,
            'preview_inventory_revision' => $inventoryRevision,
            'session_revision' => $session->session_revision ?? 1,
            'stocktake_operation_mode' => $session->stocktake_operation_mode ?? StocktakeSession::MODE_MOVEMENT_AWARE,
            'stocktake_scope_type' => $session->stocktake_scope_type ?? StocktakeSession::SCOPE_SELECTED_PRODUCTS,
            'summary' => $summary,
            'lines' => $lineResults->values()->all(),
        ];
    }

    public function reconcileLine(StocktakeSession $session, StocktakeLine $line): array
    {
        $inventory = BranchInventory::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('branch_id', $session->branch_id)
            ->where('product_id', $line->product_id)
            ->first();

        if (!$inventory) {
            throw new \RuntimeException("missing_branch_inventory: stocktake_session_id={$session->id}; stocktake_line_id={$line->id}; product_id={$line->product_id}; branch_id={$session->branch_id}");
        }

        $latestSequence = $this->latestBranchSequence($session->tenant_id, $session->branch_id);
        if ($line->counted_quantity === null) {
            throw new \RuntimeException("uncounted_line: stocktake_session_id={$session->id}; stocktake_line_id={$line->id}");
        }

        $countedQuantity = (float) $line->counted_quantity;
        $countedSequence = $line->counted_movement_sequence ?? $latestSequence;
        $movementAfter = $this->movementEvidence($session, $line->product_id, $countedSequence, $latestSequence);
        $expectedAtPosting = (float) $inventory->current_stock;
        $projected = $countedQuantity + (float) $movementAfter['delta'];
        $postedVariance = round($projected - $expectedAtPosting, 4);
        $outcome = $this->outcome($postedVariance);

        return [
            'line_id' => $line->id,
            'product_id' => $line->product_id,
            'product_name' => $line->product->name ?? null,
            'expected_quantity_at_count_start' => $this->decimal($line->expected_quantity_at_count_start ?? $line->expected_quantity ?? 0),
            'count_start_movement_sequence' => $line->count_start_movement_sequence ?? 0,
            'count_snapshot_uuid' => $line->count_snapshot_uuid,
            'counted_quantity' => $this->decimal($countedQuantity),
            'physically_counted_at' => optional($line->physically_counted_at ?? $line->counted_at)->toISOString(),
            'count_recorded_at' => optional($line->count_recorded_at ?? $line->counted_at)->toISOString(),
            'counted_movement_sequence' => $countedSequence,
            'expected_quantity_at_count_time' => $this->decimal($line->expected_quantity_at_count_time ?? $line->expected_quantity ?? 0),
            'physical_count_variance_quantity' => $this->decimal($line->physical_count_variance_quantity ?? $line->variance_quantity ?? 0),
            'movement_during_count_delta' => $this->decimal($line->movement_during_count_delta ?? 0),
            'movement_during_count_summary' => $line->movement_during_count_summary ?? $this->emptySummary(),
            'movement_during_count_sequence_from' => $line->movement_during_count_sequence_from,
            'movement_during_count_sequence_to' => $line->movement_during_count_sequence_to,
            'movement_during_count_count' => $line->movement_during_count_count ?? 0,
            'movement_after_count_delta' => $movementAfter['delta'],
            'movement_after_count_summary' => $movementAfter['summary'],
            'movement_after_count_sequence_from' => $movementAfter['sequence_from'],
            'movement_after_count_sequence_to' => $movementAfter['sequence_to'],
            'movement_after_count_count' => $movementAfter['count'],
            'expected_quantity_at_posting' => $this->decimal($expectedAtPosting),
            'posting_inventory_revision_before' => $inventory->inventory_revision ?? 1,
            'counted_quantity_projected_to_posting' => $this->decimal($projected),
            'posted_variance_quantity' => $this->decimal($postedVariance),
            'posting_outcome' => $outcome,
            'projection_policy_version' => self::PROJECTION_POLICY_VERSION,
            'reason_code' => $line->reason_code,
            'reason_schema_version' => $line->reason_schema_version ?? 1,
            'posting_snapshot' => null,
        ];
    }

    public function summary(StocktakeSession $session, Collection $lineResults): array
    {
        return [
            'stocktake_operation_mode' => $session->stocktake_operation_mode ?? StocktakeSession::MODE_MOVEMENT_AWARE,
            'stocktake_scope_type' => $session->stocktake_scope_type ?? StocktakeSession::SCOPE_SELECTED_PRODUCTS,
            'projection_policy_version' => self::PROJECTION_POLICY_VERSION,
            'line_count' => $lineResults->count(),
            'movement_count' => $lineResults->sum('movement_after_count_count'),
            'zero_variance_line_count' => $lineResults->where('posting_outcome', StocktakeLine::OUTCOME_NO_CORRECTION)->count(),
            'positive_correction_line_count' => $lineResults->where('posting_outcome', StocktakeLine::OUTCOME_POSITIVE_CORRECTION)->count(),
            'negative_correction_line_count' => $lineResults->where('posting_outcome', StocktakeLine::OUTCOME_NEGATIVE_CORRECTION)->count(),
            'total_positive_adjustment' => $this->decimal($lineResults->sum(fn ($line) => max(0, (float) $line['posted_variance_quantity']))),
            'total_negative_adjustment' => $this->decimal($lineResults->sum(fn ($line) => min(0, (float) $line['posted_variance_quantity']))),
            'movement_after_count_summary' => $this->mergeSummaries($lineResults->pluck('movement_after_count_summary')),
        ];
    }

    public function decimal(float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }

    public function outcome(float $postedVariance): string
    {
        if ($postedVariance > 0.0001) {
            return StocktakeLine::OUTCOME_POSITIVE_CORRECTION;
        }

        if ($postedVariance < -0.0001) {
            return StocktakeLine::OUTCOME_NEGATIVE_CORRECTION;
        }

        return StocktakeLine::OUTCOME_NO_CORRECTION;
    }

    private function movementEvidence(StocktakeSession $session, string $productId, int $fromSequence, int $toSequence): array
    {
        if ($toSequence <= $fromSequence) {
            return [
                'delta' => $this->decimal(0),
                'summary' => $this->emptySummary(),
                'sequence_from' => null,
                'sequence_to' => null,
                'count' => 0,
            ];
        }

        $movements = InventoryMovement::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('branch_id', $session->branch_id)
            ->where('product_id', $productId)
            ->whereIn('movement_type', self::ELIGIBLE_MOVEMENT_TYPES)
            ->where('movement_sequence', '>', $fromSequence)
            ->where('movement_sequence', '<=', $toSequence)
            ->where(function ($query) use ($session) {
                $query->where('source_type', '!=', 'stocktake_session')
                    ->orWhere('source_id', '!=', $session->id)
                    ->orWhereNull('source_type')
                    ->orWhereNull('source_id');
            })
            ->orderBy('movement_sequence')
            ->get();

        return [
            'delta' => $this->decimal($movements->sum(fn (InventoryMovement $movement) => (float) $movement->quantity_change)),
            'summary' => $this->summarizeMovements($movements),
            'sequence_from' => $movements->min('movement_sequence'),
            'sequence_to' => $movements->max('movement_sequence'),
            'count' => $movements->count(),
        ];
    }

    private function summarizeMovements(Collection $movements): array
    {
        $summary = $this->emptySummary();

        foreach ($movements as $movement) {
            $bucket = $this->movementBucket($movement->movement_type);
            $summary[$bucket] = $this->decimal((float) $summary[$bucket] + (float) $movement->quantity_change);
            $summary['net'] = $this->decimal((float) $summary['net'] + (float) $movement->quantity_change);
        }

        return $summary;
    }

    private function mergeSummaries(Collection $summaries): array
    {
        $merged = $this->emptySummary();

        foreach ($summaries as $summary) {
            foreach ($merged as $key => $value) {
                $merged[$key] = $this->decimal((float) $value + (float) ($summary[$key] ?? 0));
            }
        }

        return $merged;
    }

    private function movementBucket(string $movementType): string
    {
        return match ($movementType) {
            'sale_deduction' => 'sales',
            'refund_return' => 'refunds',
            'void_reversal' => 'voids',
            'supplier_receiving' => 'receiving',
            'supplier_return' => 'supplier_returns',
            'ibt_dispatch', 'ibt_receipt' => 'transfers',
            'manual_adjustment' => 'adjustments',
            'stock_correction' => 'other_stocktake_corrections',
            default => 'other',
        };
    }

    private function emptySummary(): array
    {
        return [
            'sales' => '0.0000',
            'refunds' => '0.0000',
            'voids' => '0.0000',
            'receiving' => '0.0000',
            'supplier_returns' => '0.0000',
            'transfers' => '0.0000',
            'adjustments' => '0.0000',
            'other_stocktake_corrections' => '0.0000',
            'other' => '0.0000',
            'net' => '0.0000',
        ];
    }
}
