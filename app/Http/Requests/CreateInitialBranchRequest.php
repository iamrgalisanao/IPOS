<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateInitialBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by middleware and controller
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'branch_name' => ['required', 'string', 'max:255', 'min:2'],
            'branch_code' => ['required', 'string', 'max:50', 'min:2', 'unique:branches,branch_code'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'branch_name.required' => 'Branch name is required.',
            'branch_name.min' => 'Branch name must be at least 2 characters.',
            'branch_name.max' => 'Branch name must not exceed 255 characters.',
            'branch_code.required' => 'Branch code is required.',
            'branch_code.min' => 'Branch code must be at least 2 characters.',
            'branch_code.max' => 'Branch code must not exceed 50 characters.',
            'branch_code.unique' => 'This branch code is already in use.',
            'location.max' => 'Location must not exceed 255 characters.',
        ];
    }
}
