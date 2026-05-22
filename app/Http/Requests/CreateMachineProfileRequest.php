<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMachineProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'profile_code' => ['required', 'string', 'max:100', 'min:2'],
            'machine_identification_number' => ['required', 'string', 'max:100'],
            'machine_serial_number' => ['required', 'string', 'max:100'],
            'permit_to_use_number' => ['required', 'string', 'max:100'],
            'authority_to_generate_control_number' => ['required', 'string', 'max:100'],
            'supplier_accreditation_number' => ['required', 'string', 'max:100'],
            'permit_issued_at' => ['nullable', 'date'],
            'software_license_number' => ['nullable', 'string', 'max:100'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_tin' => ['nullable', 'string', 'max:32'],
            'supplier_branch_code' => ['nullable', 'string', 'max:5'],
            'supplier_address' => ['nullable', 'string', 'max:255'],
            'supplier_accreditation_issued_at' => ['nullable', 'date'],
            'supplier_accreditation_expires_at' => ['nullable', 'date'],
            'terminal_identifier' => ['nullable', 'string', 'max:100'],
            'offline_sales_enabled' => ['nullable', 'boolean'],
            'offline_sequence_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/'],
            'offline_sequence_next_value' => ['nullable', 'integer', 'min:1'],
            'offline_sequence_status' => ['nullable', 'string', 'in:active,suspended,depleted'],
        ];
    }
}
