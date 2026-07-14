<?php

namespace App\Http\Requests\Dining;

use App\Models\DiningTable;
use App\Models\ServiceArea;
use App\Services\Dining\DiningLayoutMetadataValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiningTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos-layouts.manage') ?? false;
    }

    public function rules(): array
    {
        /** @var ServiceArea|null $area */
        $area = $this->route('serviceArea');
        /** @var DiningTable|null $table */
        $table = $this->route('diningTable');

        return [
            'table_number' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('dining_tables', 'table_number')
                    ->ignore($table?->id)
                    ->where('service_area_id', $area?->id),
            ],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:999'],
            'operational_state' => ['sometimes', 'required', Rule::in([
                DiningTable::STATE_AVAILABLE,
                DiningTable::STATE_RESERVED,
                DiningTable::STATE_CLEANING,
            ])],
            'position_metadata' => ['sometimes', 'required', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->has('position_metadata')) {
                return;
            }

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
