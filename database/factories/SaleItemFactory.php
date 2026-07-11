<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SaleItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 5);
        $unitPrice = $this->faker->randomFloat(2, 50, 500);
        $lineTotal = $quantity * $unitPrice;

        return [
            'product_name' => $this->faker->word,
            'sku' => $this->faker->unique()->ean8(),
            'barcode' => $this->faker->unique()->ean13(),
            'unit_of_measure' => 'piece',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $lineTotal,
            'discount_amount' => 0,
            'tax_bucket' => 'vatable',
            'tax_rate' => 12.00,
            'tax_amount' => round($lineTotal - ($lineTotal / 1.12), 4),
            'net_amount' => round($lineTotal / 1.12, 4),
            'vatable_amount' => $lineTotal,
            'vat_exempt_amount' => 0,
            'zero_rated_amount' => 0,
            'non_vat_amount' => 0,
            'line_total' => $lineTotal,
            'is_inventory_tracked' => false,
        ];
    }
}
