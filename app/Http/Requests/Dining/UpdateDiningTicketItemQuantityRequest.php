<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiningTicketItemQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'regex:/^\d{1,3}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'expected_ticket_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
