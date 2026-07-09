<?php

namespace Database\Seeders;

use App\Models\DiscountType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StatutoryDiscountTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'SC_STANDARD',
                'name' => 'Senior Citizen Standard',
                'statutory_category' => 'senior',
                'default_rate' => 0.20,
                'vat_treatment' => 'exempt',
                'requires_identity' => true,
                'requires_approval' => false,
                'applies_to_fnb' => true,
                'applies_to_retail' => true,
            ],
            [
                'code' => 'PWD_STANDARD',
                'name' => 'PWD Standard',
                'statutory_category' => 'pwd',
                'default_rate' => 0.20,
                'vat_treatment' => 'exempt',
                'requires_identity' => true,
                'requires_approval' => false,
                'applies_to_fnb' => true,
                'applies_to_retail' => true,
            ],
            [
                'code' => 'SOLO_PARENT',
                'name' => 'Solo Parent',
                'statutory_category' => 'solo_parent',
                'default_rate' => 0.10,
                'vat_treatment' => 'exempt',
                'requires_identity' => true,
                'requires_approval' => false,
                'applies_to_fnb' => true,
                'applies_to_retail' => true,
            ],
            [
                'code' => 'NAT_ATHLETE',
                'name' => 'National Athlete/Coach',
                'statutory_category' => 'other',
                'default_rate' => 0.00, // Configurable per case
                'vat_treatment' => 'exempt',
                'requires_identity' => true,
                'requires_approval' => true,
                'applies_to_fnb' => true,
                'applies_to_retail' => true,
            ],
            [
                'code' => 'MEDAL_VALOR',
                'name' => 'Medal of Valor',
                'statutory_category' => 'other',
                'default_rate' => 0.00,
                'vat_treatment' => 'exempt',
                'requires_identity' => true,
                'requires_approval' => true,
                'applies_to_fnb' => true,
                'applies_to_retail' => true,
            ],
            [
                'code' => 'DIPLOMAT',
                'name' => 'Diplomat VAT Exemption',
                'statutory_category' => 'other',
                'default_rate' => 0.00,
                'vat_treatment' => 'exempt',
                'requires_identity' => true,
                'requires_approval' => true,
                'applies_to_fnb' => true,
                'applies_to_retail' => true,
            ],
        ];

        foreach ($types as $type) {
            \DB::table('discount_types')->updateOrInsert(
                ['code' => $type['code']],
                array_merge($type, [
                    'id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
