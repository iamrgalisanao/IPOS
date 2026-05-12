<?php

namespace App\Http\Requests\Accounting;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TaxCategory;
use App\Services\Accounting\AccountingMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountingMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $metadata = $this->input('metadata');

        if (is_string($metadata)) {
            $trimmed = trim($metadata);
            if ($trimmed === '') {
                $metadata = null;
            } else {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
        }

        $this->merge([
            'provider' => is_string($this->input('provider')) ? strtolower(trim((string) $this->input('provider'))) : $this->input('provider'),
            'mapping_type' => is_string($this->input('mapping_type')) ? strtolower(trim((string) $this->input('mapping_type'))) : $this->input('mapping_type'),
            'status' => is_string($this->input('status')) ? strtolower(trim((string) $this->input('status'))) : $this->input('status'),
            'pos_entity_type' => blank($this->input('pos_entity_type')) ? null : strtolower(trim((string) $this->input('pos_entity_type'))),
            'pos_entity_id' => blank($this->input('pos_entity_id')) ? null : trim((string) $this->input('pos_entity_id')),
            'pos_key' => blank($this->input('pos_key')) ? null : trim((string) $this->input('pos_key')),
            'external_name' => blank($this->input('external_name')) ? null : trim((string) $this->input('external_name')),
            'branch_id' => blank($this->input('branch_id')) ? null : $this->input('branch_id'),
            'metadata' => $metadata,
        ]);
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(AccountingMappingService::supportedProviders())],
            'mapping_type' => ['required', Rule::in(AccountingMappingService::supportedTypes())],
            'branch_id' => ['nullable', Rule::exists(Branch::class, 'id')],
            'pos_entity_type' => ['nullable', Rule::in(['tax_category', 'payment_method', 'product', 'customer'])],
            'pos_entity_id' => ['nullable', 'uuid'],
            'pos_key' => ['nullable', 'string', 'max:100'],
            'external_id' => ['required', 'string', 'max:255'],
            'external_name' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'status' => ['required', Rule::in(AccountingMappingService::supportedStatuses())],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('mapping_type');
            $entityType = $this->input('pos_entity_type');
            $entityId = $this->input('pos_entity_id');
            $branchId = $this->input('branch_id');

            if ($type === AccountingMappingService::TYPE_ACCOUNT) {
                if (blank($this->input('pos_key'))) {
                    $validator->errors()->add('pos_key', 'Account mappings require a POS key.');
                }

                return;
            }

            if ($type === AccountingMappingService::TYPE_TAX_CODE) {
                if ($entityType !== 'tax_category') {
                    $validator->errors()->add('pos_entity_type', 'Tax code mappings must target a tax category.');
                } elseif (!$this->entityExists(TaxCategory::class, $entityId)) {
                    $validator->errors()->add('pos_entity_id', 'The selected tax category was not found.');
                }

                return;
            }

            if ($type === AccountingMappingService::TYPE_PAYMENT_METHOD) {
                if ($entityType !== 'payment_method') {
                    $validator->errors()->add('pos_entity_type', 'Payment method mappings must target a payment method.');
                } elseif (!$this->entityExists(PaymentMethod::class, $entityId)) {
                    $validator->errors()->add('pos_entity_id', 'The selected payment method was not found.');
                }

                return;
            }

            if ($type === AccountingMappingService::TYPE_PRODUCT) {
                if ($entityType !== 'product') {
                    $validator->errors()->add('pos_entity_type', 'Product mappings must target a product.');
                } elseif (!$this->entityExists(Product::class, $entityId)) {
                    $validator->errors()->add('pos_entity_id', 'The selected product was not found.');
                }

                return;
            }

            if ($type === AccountingMappingService::TYPE_CUSTOMER) {
                if ($entityType !== 'customer') {
                    $validator->errors()->add('pos_entity_type', 'Customer mappings must use the customer entity type.');
                }
                if (blank($entityId)) {
                    $validator->errors()->add('pos_entity_id', 'Customer mappings require a customer identifier.');
                }
            }

            if ($branchId && !$this->entityExists(Branch::class, $branchId)) {
                $validator->errors()->add('branch_id', 'The selected branch was not found.');
            }
        });
    }

    protected function entityExists(string $modelClass, ?string $id): bool
    {
        return filled($id) && $modelClass::query()->whereKey($id)->exists();
    }
}