<?php

namespace App\Services\POS;

class PosLayoutSchemaValidator
{
    /**
     * Validates that the provided schema matches the minimal safe shape for a POS Layout.
     *
     * Rules:
     * - Must have 'grid' object with positive integer 'rows' and 'columns'.
     * - Must have 'tiles' array.
     * - Tile references must not contain pricing, tax, or checkout logic (by structure implication,
     *   they should just be layout coordinates and item references).
     *
     * @param array $schema
     * @return bool
     */
    public static function validate(array $schema): bool
    {
        if (!isset($schema['grid']) || !is_array($schema['grid'])) {
            return false;
        }

        if (!isset($schema['grid']['rows']) || !is_int($schema['grid']['rows']) || $schema['grid']['rows'] <= 0) {
            return false;
        }

        if (!isset($schema['grid']['columns']) || !is_int($schema['grid']['columns']) || $schema['grid']['columns'] <= 0) {
            return false;
        }

        if (!isset($schema['tiles']) || !is_array($schema['tiles'])) {
            return false;
        }

        $occupied = [];

        foreach ($schema['tiles'] as $tile) {
            if (!is_array($tile)) {
                return false;
            }

            // Must have coordinates
            if (!isset($tile['x']) || !is_int($tile['x']) || $tile['x'] < 0) {
                return false;
            }
            if (!isset($tile['y']) || !is_int($tile['y']) || $tile['y'] < 0) {
                return false;
            }

            // Boundary check
            if ($tile['x'] >= $schema['grid']['columns'] || $tile['y'] >= $schema['grid']['rows']) {
                return false;
            }

            // Overlap check (1x1 tiles only for now)
            $pos = "{$tile['x']},{$tile['y']}";
            if (isset($occupied[$pos])) {
                return false;
            }
            $occupied[$pos] = true;

            // Cannot contain malicious/checkout-altering fields
            $forbiddenKeys = ['price', 'tax', 'inventory', 'discount'];
            foreach ($forbiddenKeys as $forbidden) {
                if (array_key_exists($forbidden, $tile)) {
                    return false;
                }
            }
        }

        return true;
    }
}
