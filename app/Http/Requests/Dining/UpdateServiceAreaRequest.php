<?php

namespace App\Http\Requests\Dining;

use App\Models\ServiceArea;
use App\Services\Dining\DiningLayoutMetadataValidator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos-layouts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'layout_metadata' => ['sometimes', 'required', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->has('layout_metadata')) {
                // Continue to normalized name validation below.
            } else {
                try {
                    app(DiningLayoutMetadataValidator::class)->validateLayout($this->input('layout_metadata', []));
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }

            if ($this->filled('name')) {
                /** @var ServiceArea|null $area */
                $area = $this->route('serviceArea');
                $normalized = app(\App\Services\Dining\DiningLayoutService::class)
                    ->normalizeName((string) $this->input('name'));

                $exists = ServiceArea::query()
                    ->where('branch_id', $area?->branch_id)
                    ->where('normalized_name', $normalized)
                    ->whereKeyNot($area?->id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'A service area with this name already exists for the selected branch.');
                }
            }
        });
    }
}
