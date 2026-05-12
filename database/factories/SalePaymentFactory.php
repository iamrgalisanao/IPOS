<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalePaymentFactory extends Factory
{
    protected $model = SalePayment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'sale_id' => Sale::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'shift_id' => Shift::factory(),
            'payment_type' => 'sale',
            'amount' => $this->faker->randomFloat(4, 1, 1000),
            'status' => 'paid',
            'paid_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
