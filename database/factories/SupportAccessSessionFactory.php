<?php

namespace Database\Factories;

use App\Models\SupportAccessSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportAccessSession>
 */
class SupportAccessSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_user_id' => User::factory()->platformSupport(),
            'tenant_id' => Tenant::factory(),
            'branch_id' => null,
            'reason' => $this->faker->sentence(),
            'approved_by' => null,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'status' => SupportAccessSession::STATUS_ACTIVE,
            'masking_profile' => 'default',
            'metadata' => [],
        ];
    }
}