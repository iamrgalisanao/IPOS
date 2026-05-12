<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashDrawerEventFactory extends Factory
{
    protected $model = CashDrawerEvent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'shift_id' => Shift::factory(),
            'cashier_id' => User::factory(),
            'event_type' => CashDrawerEvent::TYPE_CASH_IN,
            'amount' => '100.0000',
            'reason_code' => 'TEST_REASON',
            'created_by' => User::factory(),
            'occurred_at' => now(),
        ];
    }
}
