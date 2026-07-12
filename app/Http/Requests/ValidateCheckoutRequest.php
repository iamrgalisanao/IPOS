<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateCheckoutRequest extends FormRequest
{
    /**
     * Fields that are explicitly forbidden from client item payloads.
     * These are rejected with 422 to prove the safety boundary in tests.
     */
    private const UNSAFE_ITEM_FIELDS = [
        'cost_price',
        'quickbooks_id',
        'accounting_account_id',
        'sync_status',
        'outbox_status',
        'reconciliation_status',
        'audit_metadata',
    ];

    public function authorize(): bool
    {
        return true; // Authorization is enforced via route middleware (auth, tenant, branch, permission:create_sale)
    }

    public function rules(): array
    {
        return [
            'client_request_uuid'      => ['required', 'uuid'],
            'cart_state'               => ['sometimes', 'string'],
            'is_training_mode'         => ['sometimes', 'boolean'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'uuid'],
            'items.*.quantity'         => ['required', 'numeric', 'gt:0'],
            // The following are accepted from the client but will be IGNORED — backend uses snapshot values.
            'items.*.unit_price'       => ['sometimes', 'numeric'],
            'items.*.tax_category_id'  => ['sometimes', 'nullable', 'string'],
            'items.*.tax_type'         => ['sometimes', 'nullable', 'string'],
            'items.*.tax_rate'         => ['sometimes', 'numeric'],
            'estimated_totals'         => ['sometimes', 'array'],
            'estimated_totals.subtotal' => ['sometimes', 'numeric'],
            'estimated_totals.tax_total' => ['sometimes', 'numeric'],
            'estimated_totals.total'   => ['sometimes', 'numeric'],
            'statutory_discount' => ['sometimes', 'array'],
            'statutory_discount.discount_type_id' => ['required_with:statutory_discount', 'uuid', 'exists:discount_types,id'],
            'statutory_discount.manager_approval_id' => ['sometimes', 'nullable', 'uuid'],
            'statutory_discount.options' => ['sometimes', 'array'],
            'statutory_discount.options.application_mode' => ['sometimes', 'string'],
            'statutory_discount.options.eligible_person_count' => ['sometimes', 'integer', 'min:1'],
            'statutory_discount.options.total_pax_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'statutory_discount.options.memc_base_value' => ['sometimes', 'numeric', 'min:0'],
            'statutory_discount.options.beneficiaries' => ['sometimes', 'array'],
        ];
    }

    /**
     * After validation passes, check for presence of any unsafe fields.
     * This makes the safety boundary explicit and testable.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);
            foreach ($items as $index => $item) {
                foreach (self::UNSAFE_ITEM_FIELDS as $unsafeField) {
                    if (array_key_exists($unsafeField, $item)) {
                        $validator->errors()->add(
                            "items.{$index}.{$unsafeField}",
                            "Field [{$unsafeField}] is not permitted in POS checkout submissions."
                        );
                    }
                }
            }

            // Also reject unsafe top-level fields
            foreach (self::UNSAFE_ITEM_FIELDS as $unsafeField) {
                if ($this->has($unsafeField)) {
                    $validator->errors()->add($unsafeField, "Field [{$unsafeField}] is not permitted.");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'client_request_uuid.required' => 'A client_request_uuid is required to identify this checkout attempt.',
            'client_request_uuid.uuid'     => 'The client_request_uuid must be a valid UUID.',
            'items.required'               => 'At least one item is required in the cart.',
            'items.min'                    => 'The cart must contain at least one item.',
            'items.*.product_id.required'  => 'Each item must specify a product_id.',
            'items.*.product_id.uuid'      => 'Each product_id must be a valid UUID.',
            'items.*.quantity.required'    => 'Each item must specify a quantity.',
            'items.*.quantity.gt'          => 'Quantity must be greater than zero.',
        ];
    }
}
