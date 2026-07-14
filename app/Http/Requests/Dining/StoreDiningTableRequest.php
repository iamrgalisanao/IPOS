<?php

namespace App\Http\Requests\Dining;

use App\Models\DiningTable;
use App\Models\ServiceArea;
use App\Services\Dining\DiningLayoutMetadataValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiningTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos-layouts.manage') ?? false;
    }

    public function rules(): array
    {
        /** @var ServiceArea|null $area */
        $area = $this->route('serviceArea');

        return [
            'table_number' => [
                'required',
                'string',
                'max:64',
                Rule::unique('dining_tables', 'table_number')
                    ->where('service_area_id', $area?->id),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:999'],
            'operational_state' => ['sometimes', 'required', Rule::in([
                DiningTable::STATE_AVAILABLE,
                DiningTable::STATE_RESERVED,
                DiningTable::STATE_CLEANING,
            ])],
            'position_metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var ServiceArea|null $area */
            $area = $this->route('serviceArea');
            if (!$area) {
                return;
            }

            try {
                $layout = app(DiningLayoutMetadataValidator::class)->validateLayout($area->layout_metadata);
                app(DiningLayoutMetadataValidator::class)->validatePosition($this->input('position_metadata', []), $layout);
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
