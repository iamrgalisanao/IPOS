<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class CreateDiningTicketSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checkout_request_uuid' => ['required', 'uuid'],
            'expected_ticket_revision' => ['required', 'integer', 'min:1'],
            'is_training_mode' => ['sometimes', 'boolean'],
        ];
    }
}
