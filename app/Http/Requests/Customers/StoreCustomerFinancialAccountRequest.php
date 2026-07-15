<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Services\Customers\CustomerFinancialAccountService;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customer-accounts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'id' => ['prohibited'],
            'customer_id' => ['nullable', 'uuid'],
            'display_name' => ['required_without:customer_id', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('currency_code')) {
            $this->merge(['currency_code' => strtoupper((string) $this->input('currency_code'))]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tenant = app(TenantContext::class)->getTenant();

            if ($this->filled('customer_id')) {
                $customer = Customer::query()->whereKey($this->input('customer_id'))->first();

                if (!$customer) {
                    $validator->errors()->add('customer_id', 'The selected customer is invalid.');
                }
            }

            if ($this->filled('external_reference')) {
                $exists = Customer::query()
                    ->where('external_reference', $this->input('external_reference'))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('external_reference', 'The external reference is already used by another customer.');
                }
            }

            if ($this->filled('currency_code') && $this->input('currency_code') !== strtoupper($tenant->currency ?? 'PHP')) {
                $validator->errors()->add('currency_code', 'The currency code must match the tenant currency.');
            }

            if ($this->filled('display_name')) {
                $normalized = app(CustomerFinancialAccountService::class)
                    ->normalizeName((string) $this->input('display_name'));

                if ($normalized === '') {
                    $validator->errors()->add('display_name', 'The display name is required.');
                }
            }
        });
    }
}
