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

            // 25 expanded audit metadata validation rules
            'imports.*.tenant_id'                => ['sometimes', 'nullable', 'uuid'],
            'imports.*.branch_id'                => ['sometimes', 'nullable', 'uuid'],
            'imports.*.terminal_id'              => ['sometimes', 'nullable', 'uuid'],
            'imports.*.device_id'                => ['sometimes', 'nullable', 'string', 'max:64'],
            'imports.*.cashier_shift_id'         => ['sometimes', 'nullable', 'uuid'],
            'imports.*.timecard_id'              => ['sometimes', 'nullable', 'uuid'],
            'imports.*.local_transaction_reference'=> ['sometimes', 'nullable', 'string', 'max:64'],
            'imports.*.local_receipt_number'      => ['sometimes', 'nullable', 'string', 'max:64'],
            'imports.*.business_date'            => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'imports.*.terminal_timestamp'       => ['sometimes', 'nullable', 'date'],
            'imports.*.timezone'                 => ['sometimes', 'nullable', 'string', 'max:64'],
            'imports.*.sales_machine_profile_id' => ['sometimes', 'nullable', 'uuid'],
            'imports.*.catalog_version_hash'     => ['sometimes', 'nullable', 'string', 'size:64'],
            'imports.*.tax_configuration_version_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'imports.*.cart_snapshot'            => ['sometimes', 'array'],
            'imports.*.payment_method'           => ['sometimes', 'string', 'in:cash'],
            'imports.*.payments'                 => ['sometimes', 'array', 'min:1'],
            'imports.*.payments.*.payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'imports.*.payments.*.amount'        => ['required', 'numeric', 'gt:0'],
            'imports.*.payments.*.reference_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'imports.*.gross_amount_centavos'    => ['sometimes', 'integer', 'min:0'],
            'imports.*.discount_total_centavos'  => ['sometimes', 'integer', 'min:0'],
            'imports.*.taxable_amount_centavos'  => ['sometimes', 'integer', 'min:0'],
            'imports.*.tax_amount_centavos'      => ['sometimes', 'integer', 'min:0'],
            'imports.*.net_amount_centavos'      => ['sometimes', 'integer', 'min:0'],
            'imports.*.payload_hash'             => ['sometimes', 'nullable', 'string', 'size:64'],
            'imports.*.sync_status'              => ['sometimes', 'nullable', 'string'],
            'imports.*.sync_attempt_count'       => ['sometimes', 'integer', 'min:0'],
            'imports.*.last_sync_attempt_at'     => ['sometimes', 'nullable', 'date'],
            'imports.*.previous_hash'            => ['sometimes', 'nullable', 'string', 'size:64'],
            'imports.*.row_hash'                 => ['sometimes', 'nullable', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'imports.*.items.*.unit_price.gt' => 'Each item unit_price must be greater than zero.',
            'imports.*.payments.*.payment_method_id.exists' => 'One or more payment methods are invalid.',
            'imports.*.payments.*.amount.gt' => 'Each payment amount must be greater than zero.',
        ];
    }
}
