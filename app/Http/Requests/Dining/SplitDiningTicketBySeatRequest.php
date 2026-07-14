<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class SplitDiningTicketBySeatRequest extends FormRequest
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
            'groups.*.seat_numbers' => ['required', 'array', 'min:1'],
            'groups.*.seat_numbers.*' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
