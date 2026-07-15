<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->uuid('movement_uuid')->nullable()->after('id');
            $table->unsignedSmallInteger('movement_schema_version')->default(1)->after('movement_uuid');
            $table->unsignedBigInteger('movement_sequence')->nullable()->after('movement_schema_version');
            $table->string('base_unit_id', 64)->nullable()->after('quantity_after');
            $table->string('source_unit_id', 64)->nullable()->after('base_unit_id');
            $table->decimal('source_quantity', 19, 4)->nullable()->after('source_unit_id');
            $table->json('conversion_snapshot')->nullable()->after('source_quantity');
            $table->date('business_date')->nullable()->after('conversion_snapshot');
            $table->timestamp('posted_at')->nullable()->after('business_date');
            $table->string('source_reference')->nullable()->after('reference_number');
            $table->string('source_effect_key', 160)->nullable()->after('source_reference');
            $table->json('metadata')->nullable()->after('remarks');
        });

        Schema::create('inventory_movement_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id'], 'idx_inventory_movement_sequences_branch');
        });

        $this->backfillMovementEvidence();
        $this->backfillInventoryBaselines();

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unique('movement_uuid', 'idx_inventory_movements_uuid');
            $table->unique(['tenant_id', 'branch_id', 'movement_sequence'], 'idx_inventory_movements_branch_sequence');
            $table->unique(['tenant_id', 'branch_id', 'source_type', 'source_id', 'source_effect_key'], 'idx_inventory_movements_source_effect');
            $table->index(['tenant_id', 'branch_id', 'product_id', 'movement_sequence'], 'idx_inventory_movements_stock_card');
            $table->index(['tenant_id', 'branch_id', 'business_date'], 'idx_inventory_movements_business_date');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique('idx_inventory_movements_uuid');
            $table->dropUnique('idx_inventory_movements_branch_sequence');
            $table->dropUnique('idx_inventory_movements_source_effect');
            $table->dropIndex('idx_inventory_movements_stock_card');
            $table->dropIndex('idx_inventory_movements_business_date');
        });

        Schema::dropIfExists('inventory_movement_sequences');

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn([
                'movement_uuid',
                'movement_schema_version',
                'movement_sequence',
                'base_unit_id',
                'source_unit_id',
                'source_quantity',
                'conversion_snapshot',
                'business_date',
                'posted_at',
                'source_reference',
                'source_effect_key',
                'metadata',
            ]);
        });
    }

    private function backfillMovementEvidence(): void
    {
        $groups = DB::table('inventory_movements')
            ->select('tenant_id', 'branch_id')
            ->distinct()
            ->orderBy('tenant_id')
            ->orderBy('branch_id')
            ->get();

        foreach ($groups as $group) {
            $sequence = 0;

            DB::table('inventory_movements')
                ->where('tenant_id', $group->tenant_id)
                ->where('branch_id', $group->branch_id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->each(function ($movement) use (&$sequence) {
                    $sequence++;
                    $createdAt = $movement->created_at ? Carbon::parse($movement->created_at) : now();

                    DB::table('inventory_movements')
                        ->where('id', $movement->id)
                        ->update([
                            'movement_uuid' => (string) Str::orderedUuid(),
                            'movement_schema_version' => 1,
                            'movement_sequence' => $sequence,
                            'business_date' => $createdAt->toDateString(),
                            'posted_at' => $createdAt,
                            'source_reference' => $movement->reference_number,
                            'source_effect_key' => $this->sourceEffectKey($movement),
                        ]);
                });

            DB::table('inventory_movement_sequences')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $group->tenant_id,
                'branch_id' => $group->branch_id,
                'last_sequence' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillInventoryBaselines(): void
    {
        DB::table('branch_inventories')
            ->orderBy('tenant_id')
            ->orderBy('branch_id')
            ->orderBy('product_id')
            ->get()
            ->each(function ($inventory) {
                $movementSummary = DB::table('inventory_movements')
                    ->where('tenant_id', $inventory->tenant_id)
                    ->where('branch_id', $inventory->branch_id)
                    ->where('product_id', $inventory->product_id)
                    ->selectRaw('COUNT(*) as movement_count, COALESCE(SUM(quantity_change), 0) as movement_sum')
                    ->first();

                $movementCount = (int) ($movementSummary->movement_count ?? 0);
                $movementSum = (float) ($movementSummary->movement_sum ?? 0);
                $currentStock = (float) $inventory->current_stock;
                $delta = round($currentStock - $movementSum, 4);

                if (abs($delta) < 0.0001) {
                    return;
                }

                $movementType = $movementCount === 0
                    ? 'inventory_opening_balance'
                    : 'inventory_migration_baseline';

                $sequence = $this->nextSequence($inventory->tenant_id, $inventory->branch_id);
                $now = now();
                $sourceId = $movementCount === 0 ? $inventory->id : 'epic-40-migration';
                $reference = $movementCount === 0
                    ? "opening-balance:{$inventory->id}"
                    : "epic-40-migration-baseline";

                DB::table('inventory_movements')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'movement_uuid' => (string) Str::orderedUuid(),
                    'movement_schema_version' => 1,
                    'movement_sequence' => $sequence,
                    'tenant_id' => $inventory->tenant_id,
                    'branch_id' => $inventory->branch_id,
                    'product_id' => $inventory->product_id,
                    'branch_inventory_id' => $inventory->id,
                    'original_movement_id' => null,
                    'movement_type' => $movementType,
                    'quantity_change' => number_format($delta, 4, '.', ''),
                    'quantity_before' => number_format($movementSum, 4, '.', ''),
                    'quantity_after' => number_format($currentStock, 4, '.', ''),
                    'base_unit_id' => null,
                    'source_unit_id' => null,
                    'source_quantity' => null,
                    'conversion_snapshot' => null,
                    'business_date' => $now->toDateString(),
                    'posted_at' => $now,
                    'source_type' => $movementType,
                    'source_id' => $sourceId,
                    'reference_number' => $reference,
                    'source_reference' => $reference,
                    'source_effect_key' => $movementCount === 0
                        ? "opening_balance:{$sourceId}:product:{$inventory->product_id}"
                        : "migration_baseline:epic-40:branch:{$inventory->branch_id}:product:{$inventory->product_id}",
                    'user_id' => null,
                    'reason_code' => $movementType,
                    'remarks' => $movementCount === 0
                        ? 'Epic 40 opening balance backfill.'
                        : 'Epic 40 migration baseline for legacy movement reconciliation.',
                    'metadata' => json_encode([
                        'migration' => '2026_07_15_000012_harden_inventory_movement_ledger',
                        'prior_movement_count' => $movementCount,
                        'prior_movement_sum' => number_format($movementSum, 4, '.', ''),
                        'original_current_stock' => number_format($currentStock, 4, '.', ''),
                    ]),
                    'created_at' => $now,
                ]);
            });
    }

    private function nextSequence(string $tenantId, string $branchId): int
    {
        $sequence = DB::table('inventory_movement_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->first();

        if (!$sequence) {
            DB::table('inventory_movement_sequences')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'last_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lastSequence = 0;
        } else {
            $lastSequence = (int) $sequence->last_sequence;
        }

        $next = $lastSequence + 1;

        DB::table('inventory_movement_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->update([
                'last_sequence' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }

    private function sourceEffectKey(object $movement): ?string
    {
        if (!$movement->source_type || !$movement->source_id) {
            return null;
        }

        return implode(':', [
            $this->slugSourceType((string) $movement->source_type),
            $movement->source_id,
            'product',
            $movement->product_id,
            'movement',
            $movement->id,
        ]);
    }

    private function slugSourceType(string $sourceType): string
    {
        return str_replace('\\', '_', strtolower($sourceType));
    }
};
