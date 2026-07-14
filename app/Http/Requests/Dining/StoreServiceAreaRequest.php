<?php

namespace App\Http\Requests\Dining;

use App\Services\Dining\DiningLayoutMetadataValidator;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos-layouts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'layout_metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge([
                'normalized_name' => app(\App\Services\Dining\DiningLayoutService::class)
                    ->normalizeName((string) $this->input('name')),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('name') && $this->filled('branch_id')) {
                $exists = \App\Models\ServiceArea::query()
                    ->where('branch_id', $this->input('branch_id'))
                    ->where('normalized_name', $this->input('normalized_name'))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'A service area with this name already exists for the selected branch.');
                }
            }

            try {
                app(DiningLayoutMetadataValidator::class)->validateLayout($this->input('layout_metadata', []));
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
