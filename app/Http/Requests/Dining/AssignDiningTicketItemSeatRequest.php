<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class AssignDiningTicketItemSeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'seat_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'expected_ticket_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
