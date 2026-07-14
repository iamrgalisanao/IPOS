<?php

namespace App\Http\Requests\Dining;

use App\Models\ServiceArea;
use App\Services\Dining\DiningLayoutMetadataValidator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDiningLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos-layouts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_layout_revision' => ['required', 'integer', 'min:1'],
            'layout_metadata' => ['required', 'array'],
            'tables' => ['present', 'array'],
            'tables.*.id' => ['required', 'uuid'],
            'tables.*.position_metadata' => ['required', 'array'],
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
                $layout = app(DiningLayoutMetadataValidator::class)->validateLayout($this->input('layout_metadata', []));
                foreach ($this->input('tables', []) as $index => $table) {
                    app(DiningLayoutMetadataValidator::class)->validatePosition(
                        $table['position_metadata'] ?? [],
                        $layout,
                        "tables.{$index}.position_metadata"
                    );
                }
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
