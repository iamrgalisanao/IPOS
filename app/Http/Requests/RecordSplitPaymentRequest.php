<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordSplitPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:100'],
            'payments.*.customer_financial_account_id' => ['nullable', 'uuid'],
            'payments.*.store_credit_authorization' => ['nullable', 'array'],
            'payments.*.store_credit_authorization.verification_method' => ['nullable', 'string', 'max:100'],
            'payments.*.store_credit_authorization.verification_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'payments.*.payment_method_id.exists' => 'One or more payment methods are invalid.',
            'payments.*.amount.gt' => 'Each payment amount must be positive.',
        ];
    }
}
