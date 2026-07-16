<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVarianceLog;
use App\Models\InventoryVarianceStatusEvent;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NegativeStockExceptionService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditLogger $auditLogger
    ) {}

    public function createForSaleDeduction(
        Sale $sale,
        BranchInventory $inventory,
        InventoryMovement $movement,
        float $quantityRequired,
        float $quantityBefore,
        float $quantityAfter,
        string $policy,
        ?string $parentProductId = null,
        ?string $saleItemId = null,
        ?array $conversionResolution = null
    ): NegativeStockExceptionResult {
        if ($quantityRequired <= 0) {
            throw new RuntimeException('Negative stock exception requires a positive deduction quantity.');
        }

        if ($quantityAfter >= 0) {
            throw new RuntimeException('Negative stock exception can only be created when resulting stock is negative.');
        }

        if ($policy !== 'allow_negative_with_warning') {
            throw new RuntimeException('Negative stock exception cannot be created for strict deduction policy.');
        }

        $this->assertSameTenant($sale, $inventory, $movement);

        $incrementalShortage = $quantityBefore < 0
            ? $quantityRequired
            : max(0, $quantityRequired - max($quantityBefore, 0));
        $resultingNegative = abs(min($quantityAfter, 0));
        $severity = $this->severity($quantityRequired, $incrementalShortage, $resultingNegative);
        $sourceEffectKey = (string) $movement->source_effect_key;

        return DB::transaction(function () use (
            $sale,
            $inventory,
            $movement,
            $quantityRequired,
            $quantityBefore,
            $quantityAfter,
            $policy,
            $parentProductId,
            $saleItemId,
            $conversionResolution,
            $incrementalShortage,
            $resultingNegative,
            $severity,
            $sourceEffectKey
        ) {
            $existing = InventoryVarianceLog::query()
                ->where('branch_id', $sale->branch_id)
                ->where('movement_id', $movement->id)
                ->where('variance_category', InventoryVarianceLog::CATEGORY_NEGATIVE_STOCK)
                ->first();

            if (!$existing && $sourceEffectKey !== '') {
                $existing = InventoryVarianceLog::query()
                    ->where('branch_id', $sale->branch_id)
                    ->where('source_type', 'sale')
                    ->where('source_id', $sale->id)
                    ->where('source_effect_key', $sourceEffectKey)
                    ->where('variance_category', InventoryVarianceLog::CATEGORY_NEGATIVE_STOCK)
                    ->first();
            }

            if ($existing) {
                $this->assertReplayMatches($existing, $movement, $quantityRequired, $quantityBefore, $quantityAfter, $policy, $saleItemId);
                $statusEvent = $this->initialEvent($existing, $movement, true);

                return new NegativeStockExceptionResult(
                    movement: $movement,
                    variance: $existing,
                    statusEvent: $statusEvent,
                    replayed: true,
                    quantityBefore: $quantityBefore,
                    quantityAfter: $quantityAfter,
                    incrementalShortageQuantity: $incrementalShortage,
                    resultingNegativeQuantity: $resultingNegative,
                    severity: $severity
                );
            }

            $variance = InventoryVarianceLog::create([
                'tenant_id' => $sale->tenant_id,
                'branch_id' => $sale->branch_id,
                'variance_category' => InventoryVarianceLog::CATEGORY_NEGATIVE_STOCK,
                'current_status' => InventoryVarianceLog::STATUS_OPEN,
                'movement_id' => $movement->id,
                'movement_uuid' => $movement->movement_uuid,
                'movement_sequence' => $movement->movement_sequence,
                'branch_inventory_id' => $inventory->id,
                'sale_id' => $sale->id,
                'sale_item_id' => $saleItemId,
                'product_id' => $parentProductId,
                'ingredient_id' => $inventory->product_id,
                'ingredient_product_id' => $inventory->product_id,
                'source_type' => 'sale',
                'source_id' => $sale->id,
                'source_reference' => $sale->sale_number,
                'source_effect_key' => $sourceEffectKey,
                'quantity_before' => $quantityBefore,
                'quantity_required' => $quantityRequired,
                'quantity_delta' => -$quantityRequired,
                'quantity_after' => $quantityAfter,
                'incremental_shortage_quantity' => $incrementalShortage,
                'resulting_negative_quantity' => $resultingNegative,
                'required_quantity' => $quantityRequired,
                'available_quantity_before' => $quantityBefore,
                'shortage_quantity' => $incrementalShortage,
                'resulting_quantity' => $quantityAfter,
                'unit' => $inventory->product->unit_of_measure ?? $movement->base_unit_id ?? 'piece',
                'policy' => $policy,
                'reason' => 'POS Checkout stock shortage deduction.',
                'metadata' => [
                    'sale_number' => $sale->sale_number,
                    'recipe_parent_id' => $parentProductId,
                    'severity' => $severity,
                ],
                'policy_snapshot' => $this->policySnapshot($policy),
                'unit_snapshot' => $this->unitSnapshot($movement),
                'conversion_snapshot' => $conversionResolution['snapshot'] ?? $movement->conversion_snapshot,
                'source_snapshot' => $this->sourceSnapshot($sale, $inventory, $movement, $parentProductId, $saleItemId),
                'created_by' => $sale->user_id,
            ]);

            $statusEvent = $this->initialEvent($variance, $movement, false);

            $this->auditLogger->log(
                action: 'inventory_negative_exception_created',
                auditable: $variance,
                metadata: [
                    'sale_id' => $sale->id,
                    'sale_item_id' => $saleItemId,
                    'movement_id' => $movement->id,
                    'product_id' => $inventory->product_id,
                    'incremental_shortage_quantity' => $incrementalShortage,
                    'resulting_negative_quantity' => $resultingNegative,
                    'severity' => $severity,
                ]
            );

            return new NegativeStockExceptionResult(
                movement: $movement,
                variance: $variance,
                statusEvent: $statusEvent,
                replayed: false,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                incrementalShortageQuantity: $incrementalShortage,
                resultingNegativeQuantity: $resultingNegative,
                severity: $severity
            );
        });
    }

    protected function initialEvent(InventoryVarianceLog $variance, InventoryMovement $movement, bool $replayed): InventoryVarianceStatusEvent
    {
        $existing = InventoryVarianceStatusEvent::query()
            ->where('inventory_variance_log_id', $variance->id)
            ->where('event_type', 'created')
            ->first();

        if ($existing) {
            return $existing;
        }

        return InventoryVarianceStatusEvent::create([
            'tenant_id' => $variance->tenant_id,
            'branch_id' => $variance->branch_id,
            'inventory_variance_log_id' => $variance->id,
            'from_status' => null,
            'to_status' => InventoryVarianceLog::STATUS_OPEN,
            'event_type' => 'created',
            'actor_id' => $variance->created_by,
            'event_snapshot' => [
                'movement_id' => $movement->id,
                'movement_uuid' => $movement->movement_uuid,
                'movement_sequence' => $movement->movement_sequence,
                'replayed' => $replayed,
            ],
        ]);
    }

    protected function assertReplayMatches(
        InventoryVarianceLog $variance,
        InventoryMovement $movement,
        float $quantityRequired,
        float $quantityBefore,
        float $quantityAfter,
        string $policy,
        ?string $saleItemId
    ): void {
        $checks = [
            'movement_id' => [$variance->movement_id, $movement->id],
            'quantity_required' => [$variance->quantity_required, $quantityRequired],
            'quantity_before' => [$variance->quantity_before, $quantityBefore],
            'quantity_after' => [$variance->quantity_after, $quantityAfter],
            'policy' => [$variance->policy, $policy],
            'sale_item_id' => [$variance->sale_item_id, $saleItemId],
        ];

        foreach ($checks as $field => [$existing, $incoming]) {
            if (in_array($field, ['quantity_required', 'quantity_before', 'quantity_after'], true)) {
                $existing = number_format((float) $existing, 4, '.', '');
                $incoming = number_format((float) $incoming, 4, '.', '');
            }

            if ((string) $existing !== (string) $incoming) {
                throw new RuntimeException('Negative stock exception replay drift detected.');
            }
        }
    }

    protected function assertSameTenant(Sale $sale, BranchInventory $inventory, InventoryMovement $movement): void
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($sale->tenant_id !== $tenantId || $inventory->tenant_id !== $tenantId || $movement->tenant_id !== $tenantId) {
            throw new RuntimeException('Negative stock exception tenant scope mismatch.');
        }
    }

    protected function policySnapshot(string $policy): array
    {
        return [
            'policy_schema_version' => 1,
            'resolved_policy' => $policy,
            'tenant_default' => 'strict_block',
            'branch_override' => $policy,
            'negative_threshold_quantity' => null,
            'manager_notification_required' => true,
            'policy_source' => 'branch',
            'resolved_at' => now()->toISOString(),
        ];
    }

    protected function unitSnapshot(InventoryMovement $movement): array
    {
        return [
            'unit_snapshot_version' => 1,
            'base_unit_id' => $movement->base_unit_id,
            'source_unit_id' => $movement->source_unit_id,
            'source_quantity' => (string) $movement->source_quantity,
            'resolved_quantity' => number_format(abs((float) $movement->quantity_change), 4, '.', ''),
        ];
    }

    protected function sourceSnapshot(
        Sale $sale,
        BranchInventory $inventory,
        InventoryMovement $movement,
        ?string $parentProductId,
        ?string $saleItemId
    ): array {
        return [
            'source_snapshot_version' => 1,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_item_id' => $saleItemId,
            'parent_product_id' => $parentProductId,
            'ingredient_product_id' => $inventory->product_id,
            'branch_inventory_id' => $inventory->id,
            'movement_id' => $movement->id,
            'business_date' => $movement->business_date?->toDateString(),
            'posted_at' => $movement->posted_at?->toISOString(),
            'actor_id' => $sale->user_id,
        ];
    }

    protected function severity(float $quantityRequired, float $incrementalShortage, float $resultingNegative): string
    {
        if ($quantityRequired <= 0) {
            return 'diagnostic';
        }

        $ratio = $incrementalShortage / $quantityRequired;

        if ($resultingNegative > $quantityRequired || $ratio > 1) {
            return 'critical';
        }

        return $ratio > 0.25 ? 'high' : 'warning';
    }
}
