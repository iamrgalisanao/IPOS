<?php

namespace App\Http\Requests\POS;

use Illuminate\Foundation\Http\FormRequest;

class SyncBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization enforced by route middleware (permission:create_sale)
    }

    public function rules(): array
    {
        return [
            'batch_reference'                    => ['required', 'string', 'max:64'],
            'imports'                            => ['required', 'array', 'min:1', 'max:500'],
            'imports.*.offline_sequence_number'  => ['required', 'string'],
            'imports.*.submitted_at'             => ['required', 'date'],
            'imports.*.items'                    => ['required', 'array', 'min:1'],
            'imports.*.items.*.product_id'       => ['required', 'uuid'],
            'imports.*.items.*.quantity'         => ['required', 'integer', 'min:1'],
            'imports.*.items.*.unit_price'       => ['required', 'numeric', 'gt:0'],
            'imports.*.client_subtotal'          => ['required', 'numeric', 'min:0'],
            'imports.*.client_tax_total'         => ['required', 'numeric', 'min:0'],
            'imports.*.client_total'             => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'imports.*.items.*.unit_price.gt' => 'Each item unit_price must be greater than zero.',
        ];
    }
}
