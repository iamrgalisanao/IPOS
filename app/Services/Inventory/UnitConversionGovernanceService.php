<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\UnitConversion;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UnitConversionGovernanceService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    public function create(array $data, ?string $userId = null): UnitConversion
    {
        return DB::transaction(function () use ($data, $userId) {
            $payload = $this->validatePayload($data);
            $this->assertProductScope($payload);
            $this->assertRuleSafety($payload);
            $this->assertNoActiveRule($payload);

            [$numerator, $denominator] = $this->normalizeFactor(
                (string) ($payload['factor_numerator'] ?? $payload['conversion_factor']),
                (string) ($payload['factor_denominator'] ?? '1')
            );

            return UnitConversion::create(array_merge($payload, [
                'tenant_id' => $this->tenantContext->getTenantId(),
                'conversion_factor' => $this->factorToDecimal($numerator, $denominator),
                'factor_numerator' => $numerator,
                'factor_denominator' => $denominator,
                'source_unit_kind' => $payload['source_unit_kind'] ?? UnitConversion::classifyUnit($payload['from_unit'])[0],
                'target_unit_kind' => $payload['target_unit_kind'] ?? UnitConversion::classifyUnit($payload['to_unit'])[0],
                'unit_kind_confidence' => $this->unitKindConfidence($payload),
                'is_active' => $payload['is_active'] ?? true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]));
        });
    }

    public function replace(UnitConversion $current, array $data, ?string $userId = null): UnitConversion
    {
        return DB::transaction(function () use ($current, $data, $userId) {
            $current = UnitConversion::whereKey($current->id)->lockForUpdate()->firstOrFail();
            $this->assertTenant($current);

            $payload = $this->validatePayload($data);
            $this->assertProductScope($payload);
            $this->assertRuleSafety($payload);

            [$numerator, $denominator] = $this->normalizeFactor(
                (string) ($payload['factor_numerator'] ?? $payload['conversion_factor']),
                (string) ($payload['factor_denominator'] ?? '1')
            );

            $current->update([
                'is_active' => false,
                'updated_by' => $userId,
            ]);

            $this->assertNoActiveRule($payload);

            return UnitConversion::create(array_merge($payload, [
                'tenant_id' => $this->tenantContext->getTenantId(),
                'conversion_factor' => $this->factorToDecimal($numerator, $denominator),
                'factor_numerator' => $numerator,
                'factor_denominator' => $denominator,
                'source_unit_kind' => $payload['source_unit_kind'] ?? UnitConversion::classifyUnit($payload['from_unit'])[0],
                'target_unit_kind' => $payload['target_unit_kind'] ?? UnitConversion::classifyUnit($payload['to_unit'])[0],
                'unit_kind_confidence' => $this->unitKindConfidence($payload),
                'version' => ((int) $current->version) + 1,
                'supersedes_conversion_id' => $current->id,
                'is_active' => $payload['is_active'] ?? true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]));
        });
    }

    public function deactivate(UnitConversion $conversion, ?string $userId = null): UnitConversion
    {
        return DB::transaction(function () use ($conversion, $userId) {
            $conversion = UnitConversion::whereKey($conversion->id)->lockForUpdate()->firstOrFail();
            $this->assertTenant($conversion);

            $conversion->update([
                'is_active' => false,
                'updated_by' => $userId,
            ]);

            return $conversion;
        });
    }

    protected function validatePayload(array $data): array
    {
        $validator = Validator::make($data, [
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'from_unit' => ['required', 'string', 'max:50', 'different:to_unit'],
            'to_unit' => ['required', 'string', 'max:50'],
            'conversion_factor' => ['required_without:factor_numerator', 'numeric', 'gt:0'],
            'factor_numerator' => ['nullable', 'numeric', 'gt:0'],
            'factor_denominator' => ['nullable', 'numeric', 'gt:0'],
            'source_unit_kind' => ['nullable', 'string', 'in:mass,volume,count,package,custom'],
            'target_unit_kind' => ['nullable', 'string', 'in:mass,volume,count,package,custom'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    protected function assertProductScope(array $payload): void
    {
        if (empty($payload['product_id'])) {
            return;
        }

        $product = Product::whereKey($payload['product_id'])->first();

        if (!$product || $product->tenant_id !== $this->tenantContext->getTenantId()) {
            throw ValidationException::withMessages([
                'product_id' => 'The selected product is not available for this tenant.',
            ]);
        }
    }

    protected function assertRuleSafety(array $payload): void
    {
        if (UnitConversion::isCanonicalMetricPair($payload['from_unit'], $payload['to_unit'])) {
            throw ValidationException::withMessages([
                'from_unit' => 'Canonical metric conversions are reserved and cannot be overridden.',
            ]);
        }

        $sourceKind = $payload['source_unit_kind'] ?? UnitConversion::classifyUnit($payload['from_unit'])[0];
        $targetKind = $payload['target_unit_kind'] ?? UnitConversion::classifyUnit($payload['to_unit'])[0];
        $requiresProductScope = $sourceKind !== $targetKind
            || $sourceKind === 'custom'
            || $targetKind === 'custom'
            || $this->usesBusinessPackageUnit($payload['from_unit'])
            || $this->usesBusinessPackageUnit($payload['to_unit']);

        if ($requiresProductScope && empty($payload['product_id'])) {
            throw ValidationException::withMessages([
                'product_id' => 'Cross-dimension, custom, and business-package conversions require product scope.',
            ]);
        }
    }

    protected function assertNoActiveRule(array $payload): void
    {
        $exists = UnitConversion::query()
            ->where('scope_key', UnitConversion::scopeKey($this->tenantContext->getTenantId(), $payload['product_id'] ?? null))
            ->where('normalized_from_unit', UnitConversion::normalizeUnit($payload['from_unit']))
            ->where('normalized_to_unit', UnitConversion::normalizeUnit($payload['to_unit']))
            ->where('active_slot', 'active')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'from_unit' => 'An active conversion rule for these units already exists for this scope.',
            ]);
        }
    }

    protected function assertTenant(UnitConversion $conversion): void
    {
        if ($conversion->tenant_id !== $this->tenantContext->getTenantId()) {
            throw new \RuntimeException('Cannot mutate a unit conversion from another tenant.');
        }
    }

    protected function usesBusinessPackageUnit(string $unit): bool
    {
        [$kind] = UnitConversion::classifyUnit($unit);

        return $kind === 'package' && !UnitConversion::isCanonicalPackageUnit($unit);
    }

    protected function unitKindConfidence(array $payload): string
    {
        if (!empty($payload['source_unit_kind']) && !empty($payload['target_unit_kind'])) {
            return 'certain';
        }

        return UnitConversion::classifyUnit($payload['from_unit'])[1] === 'certain'
            && UnitConversion::classifyUnit($payload['to_unit'])[1] === 'certain'
            ? 'certain'
            : 'uncertain';
    }

    protected function normalizeFactor(string $numerator, string $denominator): array
    {
        $numerator = $this->trimDecimal($numerator);
        $denominator = $this->trimDecimal($denominator);

        if ((float) $denominator <= 0) {
            throw ValidationException::withMessages([
                'factor_denominator' => 'The factor denominator must be greater than zero.',
            ]);
        }

        $scale = 8;
        $numInt = (int) round(((float) $numerator) * (10 ** $scale));
        $denInt = (int) round(((float) $denominator) * (10 ** $scale));
        $gcd = $this->gcd(abs($numInt), abs($denInt));

        if ($gcd > 0) {
            $numInt = intdiv($numInt, $gcd);
            $denInt = intdiv($denInt, $gcd);
        }

        return [
            (string) $numInt,
            (string) $denInt,
        ];
    }

    protected function factorToDecimal(string $numerator, string $denominator): float
    {
        return round((float) $numerator / (float) $denominator, 8);
    }

    protected function trimDecimal(string $value): string
    {
        $value = rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');

        return $value === '' ? '0' : $value;
    }

    protected function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }

        return $a;
    }
}
