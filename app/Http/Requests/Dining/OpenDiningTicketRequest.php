<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class OpenDiningTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'dining_table_id' => ['required', 'uuid'],
            'client_request_uuid' => ['required', 'uuid'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'reservation_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
