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
        ];
    }
}
