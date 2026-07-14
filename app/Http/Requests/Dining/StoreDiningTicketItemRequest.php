<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiningTicketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'regex:/^\d{1,3}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'seat_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'course_no' => ['nullable', 'integer', 'min:1', 'max:20'],
            'fire_group' => ['nullable', 'string', Rule::in(['starter', 'main', 'dessert', 'drinks', 'custom'])],
            'hold_until' => ['nullable', 'date', 'after_or_equal:now'],
            'preparation_station_id' => ['nullable', 'uuid'],
            'expected_ticket_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
