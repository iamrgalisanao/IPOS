<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_conversions', function (Blueprint $table) {
            $table->uuid('conversion_uuid')->nullable()->after('id');
            $table->unsignedSmallInteger('conversion_schema_version')->default(1)->after('conversion_uuid');
            $table->unsignedInteger('version')->default(1)->after('conversion_schema_version');
            $table->string('scope_key')->nullable()->after('product_id');
            $table->string('normalized_from_unit')->nullable()->after('from_unit');
            $table->string('normalized_to_unit')->nullable()->after('to_unit');
            $table->string('source_unit_kind', 32)->nullable()->after('normalized_to_unit');
            $table->string('target_unit_kind', 32)->nullable()->after('source_unit_kind');
            $table->string('unit_kind_confidence', 32)->nullable()->after('target_unit_kind');
            $table->decimal('factor_numerator', 19, 8)->nullable()->after('conversion_factor');
            $table->decimal('factor_denominator', 19, 8)->nullable()->after('factor_numerator');
            $table->foreignUuid('supersedes_conversion_id')->nullable()->after('factor_denominator');
            $table->timestamp('locked_at')->nullable()->after('is_active');
            $table->string('active_slot', 16)->nullable()->after('locked_at');
            $table->foreignUuid('created_by')->nullable()->after('active_slot')->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable()->after('updated_by');
        });

        $this->dropLegacyUniqueIndexes();
        $this->backfillConversionEvidence();

        Schema::table('unit_conversions', function (Blueprint $table) {
            $table->unique('conversion_uuid', 'unit_conversions_conversion_uuid_unique');
            $table->unique(
                ['scope_key', 'normalized_from_unit', 'normalized_to_unit', 'active_slot'],
                'unit_conversions_active_scope_unique'
            );
            $table->unique(
                ['scope_key', 'normalized_from_unit', 'normalized_to_unit', 'version'],
                'unit_conversions_version_unique'
            );
            $table->index(['tenant_id', 'product_id', 'normalized_from_unit', 'normalized_to_unit'], 'unit_conversions_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('unit_conversions', function (Blueprint $table) {
            $table->dropUnique('unit_conversions_conversion_uuid_unique');
            $table->dropUnique('unit_conversions_active_scope_unique');
            $table->dropUnique('unit_conversions_version_unique');
            $table->dropIndex('unit_conversions_lookup_index');
        });

        Schema::table('unit_conversions', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'conversion_uuid',
                'conversion_schema_version',
                'version',
                'scope_key',
                'normalized_from_unit',
                'normalized_to_unit',
                'source_unit_kind',
                'target_unit_kind',
                'unit_kind_confidence',
                'factor_numerator',
                'factor_denominator',
                'supersedes_conversion_id',
                'locked_at',
                'active_slot',
                'metadata',
            ]);
        });
    }

    private function dropLegacyUniqueIndexes(): void
    {
        foreach ([
            'DROP INDEX IF EXISTS unit_conversions_tenant_global_unique',
            'DROP INDEX IF EXISTS unit_conversions_product_specific_unique',
            'DROP INDEX IF EXISTS unit_conversions_fallback_unique',
        ] as $statement) {
            try {
                DB::statement($statement);
            } catch (\Throwable) {
                //
            }
        }

        foreach ([
            'ALTER TABLE unit_conversions DROP INDEX unit_conversions_tenant_global_unique',
            'ALTER TABLE unit_conversions DROP INDEX unit_conversions_product_specific_unique',
            'ALTER TABLE unit_conversions DROP INDEX unit_conversions_fallback_unique',
        ] as $statement) {
            try {
                DB::statement($statement);
            } catch (\Throwable) {
                //
            }
        }
    }

    private function backfillConversionEvidence(): void
    {
        DB::table('unit_conversions')
            ->orderBy('tenant_id')
            ->orderBy('product_id')
            ->orderBy('from_unit')
            ->orderBy('to_unit')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function ($conversion) {
                [$sourceKind, $sourceConfidence] = $this->classifyUnit((string) $conversion->from_unit);
                [$targetKind, $targetConfidence] = $this->classifyUnit((string) $conversion->to_unit);
                [$numerator, $denominator] = $this->normalizeFactor((string) $conversion->conversion_factor, '1');

                DB::table('unit_conversions')
                    ->where('id', $conversion->id)
                    ->update([
                        'conversion_uuid' => (string) Str::orderedUuid(),
                        'conversion_schema_version' => 1,
                        'version' => 1,
                        'scope_key' => $this->scopeKey((string) $conversion->tenant_id, $conversion->product_id),
                        'normalized_from_unit' => $this->normalizeUnit((string) $conversion->from_unit),
                        'normalized_to_unit' => $this->normalizeUnit((string) $conversion->to_unit),
                        'source_unit_kind' => $sourceKind,
                        'target_unit_kind' => $targetKind,
                        'unit_kind_confidence' => $sourceConfidence === 'certain' && $targetConfidence === 'certain' ? 'certain' : 'uncertain',
                        'factor_numerator' => $numerator,
                        'factor_denominator' => $denominator,
                        'active_slot' => $conversion->is_active ? 'active' : null,
                        'metadata' => json_encode([
                            'migration' => '2026_07_16_000001_harden_unit_conversion_governance',
                            'source_unit_kind_confidence' => $sourceConfidence,
                            'target_unit_kind_confidence' => $targetConfidence,
                        ]),
                    ]);
            });
    }

    private function normalizeUnit(string $unit): string
    {
        return strtolower(trim($unit));
    }

    private function scopeKey(string $tenantId, ?string $productId): string
    {
        return $productId ? "product:{$productId}" : "tenant:{$tenantId}";
    }

    private function classifyUnit(string $unit): array
    {
        $unit = $this->normalizeUnit($unit);

        return match ($unit) {
            'kg', 'kilogram', 'kilograms', 'gram', 'grams', 'g' => ['mass', 'certain'],
            'liter', 'litre', 'liters', 'litres', 'l', 'ml', 'milliliter', 'millilitre' => ['volume', 'certain'],
            'piece', 'pieces', 'pc', 'pcs', 'unit', 'dozen', 'gross', 'pair' => ['count', 'certain'],
            'case', 'tray', 'crate', 'bag', 'bottle', 'bucket', 'sack', 'scoop' => ['package', 'uncertain'],
            default => ['custom', 'uncertain'],
        };
    }

    private function normalizeFactor(string $numerator, string $denominator): array
    {
        $scale = 8;
        $numInt = (int) round(((float) $numerator) * (10 ** $scale));
        $denInt = (int) round(((float) $denominator) * (10 ** $scale));

        if ($denInt <= 0) {
            $denInt = 10 ** $scale;
        }

        $gcd = $this->gcd(abs($numInt), abs($denInt));

        if ($gcd > 0) {
            $numInt = intdiv($numInt, $gcd);
            $denInt = intdiv($denInt, $gcd);
        }

        return [(string) $numInt, (string) $denInt];
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }

        return $a;
    }
};
