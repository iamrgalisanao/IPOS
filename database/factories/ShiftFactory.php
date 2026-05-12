<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'cashier_id' => User::factory(),
            'opened_by' => User::factory(),
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ];
    }
}
