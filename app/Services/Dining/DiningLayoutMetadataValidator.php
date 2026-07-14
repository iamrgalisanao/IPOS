<?php

namespace App\Services\Dining;

use App\Models\DiningTable;
use Illuminate\Validation\ValidationException;

class DiningLayoutMetadataValidator
{
    public const CANVAS_WIDTH_MIN = 320;
    public const CANVAS_WIDTH_MAX = 5000;
    public const CANVAS_HEIGHT_MIN = 240;
    public const CANVAS_HEIGHT_MAX = 5000;
    public const GRID_SIZE_MIN = 1;
    public const GRID_SIZE_MAX = 100;
    public const TABLE_SIZE_MIN = 40;
    public const TABLE_SIZE_MAX = 1000;
    public const CAPACITY_MIN = 1;
    public const CAPACITY_MAX = 999;
    public const Z_INDEX_MIN = 0;
    public const Z_INDEX_MAX = 10000;

    public static function defaultLayout(): array
    {
        return [
            'version' => 1,
            'canvas_width' => 1600,
            'canvas_height' => 900,
            'grid_size' => 10,
            'background' => [
                'type' => 'none',
                'image_url' => null,
            ],
        ];
    }

    public static function defaultPosition(): array
    {
        return [
            'x' => 0,
            'y' => 0,
            'width' => 120,
            'height' => 80,
            'rotation' => 0,
            'shape' => DiningTable::SHAPE_RECTANGLE,
            'label_position' => DiningTable::LABEL_CENTER,
            'z_index' => 1,
        ];
    }

    public function validateLayout(array $metadata, string $field = 'layout_metadata'): array
    {
        $metadata = array_replace_recursive(self::defaultLayout(), $metadata);
        $errors = [];

        if (($metadata['version'] ?? null) !== 1) {
            $errors["{$field}.version"][] = 'Unsupported layout metadata version.';
        }

        foreach (['canvas_width', 'canvas_height', 'grid_size'] as $key) {
            if (!is_int($metadata[$key] ?? null)) {
                $errors["{$field}.{$key}"][] = 'The value must be an integer.';
            }
        }

        $this->assertRange($metadata['canvas_width'] ?? null, self::CANVAS_WIDTH_MIN, self::CANVAS_WIDTH_MAX, "{$field}.canvas_width", $errors);
        $this->assertRange($metadata['canvas_height'] ?? null, self::CANVAS_HEIGHT_MIN, self::CANVAS_HEIGHT_MAX, "{$field}.canvas_height", $errors);
        $this->assertRange($metadata['grid_size'] ?? null, self::GRID_SIZE_MIN, self::GRID_SIZE_MAX, "{$field}.grid_size", $errors);

        $background = $metadata['background'] ?? null;
        if (!is_array($background)) {
            $errors["{$field}.background"][] = 'The background must be an object.';
        } else {
            if (($background['type'] ?? null) !== 'none') {
                $errors["{$field}.background.type"][] = 'Version 1 supports only background type none.';
            }
            if (($background['image_url'] ?? null) !== null) {
                $errors["{$field}.background.image_url"][] = 'Version 1 does not support background images.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $metadata;
    }

    public function validatePosition(array $position, array $layout, string $field = 'position_metadata'): array
    {
        $position = array_replace(self::defaultPosition(), $position);
        $errors = [];

        foreach (['x', 'y', 'width', 'height', 'rotation', 'z_index'] as $key) {
            if (!is_int($position[$key] ?? null)) {
                $errors["{$field}.{$key}"][] = 'The value must be an integer.';
            }
        }

        $this->assertRange($position['x'] ?? null, 0, PHP_INT_MAX, "{$field}.x", $errors);
        $this->assertRange($position['y'] ?? null, 0, PHP_INT_MAX, "{$field}.y", $errors);
        $this->assertRange($position['width'] ?? null, self::TABLE_SIZE_MIN, self::TABLE_SIZE_MAX, "{$field}.width", $errors);
        $this->assertRange($position['height'] ?? null, self::TABLE_SIZE_MIN, self::TABLE_SIZE_MAX, "{$field}.height", $errors);
        $this->assertRange($position['rotation'] ?? null, 0, 359, "{$field}.rotation", $errors);
        $this->assertRange($position['z_index'] ?? null, self::Z_INDEX_MIN, self::Z_INDEX_MAX, "{$field}.z_index", $errors);

        $allowedShapes = [
            DiningTable::SHAPE_RECTANGLE,
            DiningTable::SHAPE_SQUARE,
            DiningTable::SHAPE_CIRCLE,
            DiningTable::SHAPE_OVAL,
        ];
        if (!in_array($position['shape'] ?? null, $allowedShapes, true)) {
            $errors["{$field}.shape"][] = 'The selected shape is invalid.';
        }

        $allowedLabels = [
            DiningTable::LABEL_CENTER,
            DiningTable::LABEL_TOP,
            DiningTable::LABEL_BOTTOM,
        ];
        if (!in_array($position['label_position'] ?? null, $allowedLabels, true)) {
            $errors["{$field}.label_position"][] = 'The selected label position is invalid.';
        }

        if (in_array($position['shape'] ?? null, [DiningTable::SHAPE_SQUARE, DiningTable::SHAPE_CIRCLE], true)
            && ($position['width'] ?? null) !== ($position['height'] ?? null)) {
            $errors["{$field}.height"][] = 'Square and circle tables must have equal width and height.';
        }

        if (is_int($position['x'] ?? null) && is_int($position['width'] ?? null)
            && $position['x'] + $position['width'] > ($layout['canvas_width'] ?? 0)) {
            $errors["{$field}.x"][] = 'The table must remain within the canvas width.';
        }

        if (is_int($position['y'] ?? null) && is_int($position['height'] ?? null)
            && $position['y'] + $position['height'] > ($layout['canvas_height'] ?? 0)) {
            $errors["{$field}.y"][] = 'The table must remain within the canvas height.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $position;
    }

    public function validateCapacity(int $capacity, string $field = 'capacity'): void
    {
        $errors = [];
        $this->assertRange($capacity, self::CAPACITY_MIN, self::CAPACITY_MAX, $field, $errors);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertRange(mixed $value, int $min, int $max, string $field, array &$errors): void
    {
        if (!is_int($value)) {
            return;
        }

        if ($value < $min || $value > $max) {
            $errors[$field][] = "The value must be between {$min} and {$max}.";
        }
    }
}
