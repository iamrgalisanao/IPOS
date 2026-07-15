<?php

namespace App\Http\Requests\Customers;

use App\Models\CustomerFinancialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerFinancialAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customer-accounts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(CustomerFinancialAccount::STATUSES)],
            'reason' => [
                Rule::requiredIf(fn () => in_array($this->input('status'), [
                    CustomerFinancialAccount::STATUS_SUSPENDED,
                    CustomerFinancialAccount::STATUS_CLOSED,
                ], true)),
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}
