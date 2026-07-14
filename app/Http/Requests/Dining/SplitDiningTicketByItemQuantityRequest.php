<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class SplitDiningTicketByItemQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_ticket_revision' => ['required', 'integer', 'min:1'],
            'client_request_uuid' => ['required', 'uuid'],
            'groups' => ['required', 'array', 'min:2'],
            'groups.*.label' => ['nullable', 'string', 'max:80'],
            'groups.*.items' => ['required', 'array', 'min:1'],
            'groups.*.items.*.dining_ticket_item_id' => ['required', 'uuid'],
            'groups.*.items.*.quantity' => ['required', 'regex:/^\d{1,3}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
        ];
    }
}
