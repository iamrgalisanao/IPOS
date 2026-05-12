<?php

namespace App\Services\Accounting;

use App\Models\AccountingMapping;
use App\Models\User;
use App\Services\Accounting\Contracts\AccountingMapperInterface;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountingMappingService implements AccountingMapperInterface
{
    public const PROVIDER_QUICKBOOKS = 'quickbooks';
    public const TYPE_ACCOUNT = 'account';
    public const TYPE_TAX_CODE = 'tax_code';
    public const TYPE_PAYMENT_METHOD = 'payment_method';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_CUSTOMER = 'customer';

    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected string $provider = self::PROVIDER_QUICKBOOKS
    ) {}

    public static function supportedProviders(): array
    {
        return [self::PROVIDER_QUICKBOOKS];
    }

    public static function supportedTypes(): array
    {
        return [
            self::TYPE_ACCOUNT,
            self::TYPE_TAX_CODE,
            self::TYPE_PAYMENT_METHOD,
            self::TYPE_PRODUCT,
            self::TYPE_CUSTOMER,
        ];
    }

    public static function supportedStatuses(): array
    {
        return [AccountingMapping::STATUS_ACTIVE, AccountingMapping::STATUS_INACTIVE];
    }

    public function createOrUpdate(array $attributes, ?User $actor = null): AccountingMapping
    {
        $attributes = $this->normalizeAttributes($attributes);
        $tenantId = $attributes['tenant_id'] ?? $this->tenantContext->getTenantId();

        if (blank($tenantId)) {
            throw new RuntimeException('Tenant context is required to manage accounting mappings.');
        }

        $identity = [
            'tenant_id' => $tenantId,
            'branch_id' => $attributes['branch_id'] ?? null,
            'provider' => $attributes['provider'] ?? $this->provider,
            'mapping_type' => $attributes['mapping_type'],
            'pos_entity_type' => $attributes['pos_entity_type'] ?? null,
            'pos_entity_id' => $attributes['pos_entity_id'] ?? null,
            'pos_key' => $attributes['pos_key'] ?? null,
        ];

        $mapping = AccountingMapping::query()->firstOrNew($identity);
        $this->ensureActiveScopeAvailable($identity, $attributes['status'] ?? AccountingMapping::STATUS_ACTIVE, $mapping->id);
        $mapping->fill([
            'external_id' => $attributes['external_id'],
            'external_name' => $attributes['external_name'] ?? null,
            'metadata' => $this->sanitizeMetadata($attributes['metadata'] ?? null),
            'status' => $attributes['status'] ?? AccountingMapping::STATUS_ACTIVE,
            'created_by' => $mapping->exists ? $mapping->created_by : ($attributes['created_by'] ?? $actor?->id),
            'updated_by' => $attributes['updated_by'] ?? $actor?->id,
        ]);
        $mapping->save();

        return $mapping->refresh();
    }

    public function update(AccountingMapping $mapping, array $attributes, ?User $actor = null): AccountingMapping
    {
        $attributes = $this->normalizeAttributes($attributes);

        $identity = [
            'tenant_id' => $mapping->tenant_id,
            'branch_id' => $attributes['branch_id'] ?? $mapping->branch_id,
            'provider' => $attributes['provider'] ?? $mapping->provider,
            'mapping_type' => $attributes['mapping_type'] ?? $mapping->mapping_type,
            'pos_entity_type' => array_key_exists('pos_entity_type', $attributes) ? $attributes['pos_entity_type'] : $mapping->pos_entity_type,
            'pos_entity_id' => array_key_exists('pos_entity_id', $attributes) ? $attributes['pos_entity_id'] : $mapping->pos_entity_id,
            'pos_key' => array_key_exists('pos_key', $attributes) ? $attributes['pos_key'] : $mapping->pos_key,
        ];

        $status = $attributes['status'] ?? $mapping->status;
        $this->ensureActiveScopeAvailable($identity, $status, $mapping->id);

        $mapping->fill([
            'branch_id' => $identity['branch_id'],
            'provider' => $identity['provider'],
            'mapping_type' => $identity['mapping_type'],
            'pos_entity_type' => $identity['pos_entity_type'],
            'pos_entity_id' => $identity['pos_entity_id'],
            'pos_key' => $identity['pos_key'],
            'external_id' => $attributes['external_id'],
            'external_name' => $attributes['external_name'] ?? null,
            'metadata' => $this->sanitizeMetadata($attributes['metadata'] ?? null),
            'status' => $status,
            'updated_by' => $attributes['updated_by'] ?? $actor?->id,
        ]);
        $mapping->save();

        return $mapping->refresh();
    }

    public function setStatus(AccountingMapping $mapping, string $status, ?User $actor = null): AccountingMapping
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::supportedStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Unsupported mapping status.',
            ]);
        }

        $identity = [
            'tenant_id' => $mapping->tenant_id,
            'branch_id' => $mapping->branch_id,
            'provider' => $mapping->provider,
            'mapping_type' => $mapping->mapping_type,
            'pos_entity_type' => $mapping->pos_entity_type,
            'pos_entity_id' => $mapping->pos_entity_id,
            'pos_key' => $mapping->pos_key,
        ];

        $this->ensureActiveScopeAvailable($identity, $status, $mapping->id);

        $mapping->forceFill([
            'status' => $status,
            'updated_by' => $actor?->id,
        ])->save();

        return $mapping->refresh();
    }

    public function mapAccount(string $type): string
    {
        return $this->requireMapped(
            $this->resolveExternalId(self::TYPE_ACCOUNT, posKey: $type),
            'account'
        );
    }

    public function mapTaxCode(string $posTaxCategoryId): string
    {
        return $this->requireMapped(
            $this->resolveExternalId(self::TYPE_TAX_CODE, 'tax_category', $posTaxCategoryId),
            'tax code'
        );
    }

    public function mapPaymentMethod(string $posPaymentMethodId): string
    {
        return $this->requireMapped(
            $this->resolveExternalId(self::TYPE_PAYMENT_METHOD, 'payment_method', $posPaymentMethodId),
            'payment method'
        );
    }

    public function mapProduct(?string $posProductId): ?string
    {
        if (blank($posProductId)) {
            return null;
        }

        return $this->resolveExternalId(self::TYPE_PRODUCT, 'product', $posProductId);
    }

    public function mapCustomer(?string $posCustomerId): ?string
    {
        if (blank($posCustomerId)) {
            return null;
        }

        return $this->resolveExternalId(self::TYPE_CUSTOMER, 'customer', $posCustomerId);
    }

    public function resolveExternalId(
        string $mappingType,
        ?string $posEntityType = null,
        ?string $posEntityId = null,
        ?string $posKey = null,
        ?string $provider = null
    ): ?string {
        return $this->resolveMapping($mappingType, $posEntityType, $posEntityId, $posKey, $provider)?->external_id;
    }

    public function resolveMapping(
        string $mappingType,
        ?string $posEntityType = null,
        ?string $posEntityId = null,
        ?string $posKey = null,
        ?string $provider = null
    ): ?AccountingMapping {
        $query = AccountingMapping::query()
            ->active()
            ->where('provider', $provider ?? $this->provider)
            ->where('mapping_type', $mappingType);

        if (filled($posKey)) {
            $query->where('pos_key', $posKey);
        }

        if (filled($posEntityType) || filled($posEntityId)) {
            $query->where('pos_entity_type', $posEntityType)
                ->where('pos_entity_id', $posEntityId);
        }

        $branchId = $this->branchContext->getBranchId();

        if (filled($branchId)) {
            $query->where(function (Builder $builder) use ($branchId) {
                $builder->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })->orderByRaw('case when branch_id = ? then 0 else 1 end', [$branchId]);
        } else {
            $query->whereNull('branch_id');
        }

        return $query->orderByDesc('updated_at')->first();
    }

    protected function requireMapped(?string $externalId, string $label): string
    {
        if (blank($externalId)) {
            throw new RuntimeException('Missing QuickBooks mapping for ' . $label . '.');
        }

        return $externalId;
    }

    protected function normalizeAttributes(array $attributes): array
    {
        foreach (['branch_id', 'pos_entity_type', 'pos_entity_id', 'pos_key', 'external_name'] as $field) {
            if (array_key_exists($field, $attributes) && blank($attributes[$field])) {
                $attributes[$field] = null;
            }
        }

        if (array_key_exists('provider', $attributes) && is_string($attributes['provider'])) {
            $attributes['provider'] = strtolower(trim($attributes['provider']));
        }

        if (array_key_exists('mapping_type', $attributes) && is_string($attributes['mapping_type'])) {
            $attributes['mapping_type'] = strtolower(trim($attributes['mapping_type']));
        }

        if (array_key_exists('status', $attributes) && is_string($attributes['status'])) {
            $attributes['status'] = strtolower(trim($attributes['status']));
        }

        return $attributes;
    }

    protected function ensureActiveScopeAvailable(array $identity, string $status, ?string $exceptId = null): void
    {
        if ($status !== AccountingMapping::STATUS_ACTIVE) {
            return;
        }

        $query = AccountingMapping::query()
            ->active()
            ->where('tenant_id', $identity['tenant_id'])
            ->where('provider', $identity['provider'])
            ->where('mapping_type', $identity['mapping_type']);

        foreach (['branch_id', 'pos_entity_type', 'pos_entity_id', 'pos_key'] as $field) {
            if ($identity[$field] === null) {
                $query->whereNull($field);
            } else {
                $query->where($field, $identity[$field]);
            }
        }

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'mapping' => 'An active mapping already exists for this provider and scope.',
            ]);
        }
    }

    protected function sanitizeMetadata(mixed $metadata): ?array
    {
        if (!is_array($metadata)) {
            return null;
        }

        $sanitized = $this->stripSecretKeys($metadata);

        return $sanitized === [] ? null : $sanitized;
    }

    protected function stripSecretKeys(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|password|api[_-]?key|private[_-]?key|authorization/i', $key)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->stripSecretKeys($value)
                : $this->sanitizeMetadataValue($value);
        }

        return $sanitized;
    }

    protected function sanitizeMetadataValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $sanitized = preg_replace('/Authorization\s*:\s*Bearer\s+[^\s"]+/i', '[redacted authorization]', $value);
        $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', '[redacted bearer token]', $sanitized ?? $value);

        return $sanitized ?? $value;
    }
}