<?php

namespace App\Http\Requests\POS;

use Illuminate\Foundation\Http\FormRequest;

class TerminalHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_version'                 => ['sometimes', 'nullable', 'string', 'max:64'],
            'device_id'                   => ['sometimes', 'nullable', 'string', 'max:64'],
            'config_snapshot'             => ['sometimes', 'nullable', 'array'],
            'last_snapshot_downloaded_at' => ['sometimes', 'nullable', 'date'],
            'last_successful_sync_at'     => ['sometimes', 'nullable', 'date'],
            'queue_count'                 => ['sometimes', 'integer', 'min:0'],
            'connection_state'            => ['sometimes', 'nullable', 'string', 'max:64'],
            'reported_at'                 => ['sometimes', 'nullable', 'date'],
        ];
    }
}
