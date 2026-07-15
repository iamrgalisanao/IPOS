<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlled by middleware
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'customer_financial_account_id' => ['nullable', 'uuid'],
            'store_credit_authorization' => ['nullable', 'array'],
            'store_credit_authorization.verification_method' => ['nullable', 'string', 'max:100'],
            'store_credit_authorization.verification_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
