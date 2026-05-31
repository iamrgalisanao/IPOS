<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shift\ShiftService;
use App\Services\Shift\CashDropService;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashDropThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftService $shiftService;
    protected CashDropService $cashDropService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);

        // Seed RBAC
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignToBranch($this->branch);
        $this->cashier->assignRole(\App\Models\Role::where('name', 'Cashier')->first());

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'manager@test.com',
            'password' => Hash::make('manager123'),
        ]);
        $this->manager->assignRole(\App\Models\Role::where('name', 'Branch Manager')->first());

        $this->shiftService = app(ShiftService::class);
        $this->cashDropService = app(CashDropService::class);
    }

    public function test_branch_drawer_limit_overrides_tenant_default(): void
    {
        $this->branch->update(['cash_drawer_limit' => 3000.00]);
        $this->tenant->update(['default_cash_drawer_limit' => 2000.00]);

        $resolved = $this->cashDropService->resolveThreshold($this->branch->id);
        $this->assertEquals(3000.00, $resolved);
    }

    public function test_tenant_default_is_used_when_branch_limit_is_null(): void
    {
        $this->branch->update(['cash_drawer_limit' => null]);
        $this->tenant->update(['default_cash_drawer_limit' => 2000.00]);

        $resolved = $this->cashDropService->resolveThreshold($this->branch->id);
        $this->assertEquals(2000.00, $resolved);
    }

    public function test_config_fallback_is_used_when_both_limits_are_null(): void
    {
        $this->branch->update(['cash_drawer_limit' => null]);
        $this->tenant->update(['default_cash_drawer_limit' => null]);

        $resolved = $this->cashDropService->resolveThreshold($this->branch->id);
        $this->assertEquals(5000.00, $resolved);
    }

    public function test_cash_drop_equal_to_threshold_does_not_require_manager_verification(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '6000.0000',
            $this->cashier
        );

        $this->branch->update(['cash_drawer_limit' => 5000.00]);

        // Equal to threshold
        $event = $this->cashDropService->recordCashDrop(
            $shift,
            $this->cashier,
            '5000.0000',
            'VAULT_DROP',
            'Standard shift drop'
        );

        $this->assertInstanceOf(\App\Models\CashDrawerEvent::class, $event);
        $this->assertEquals($this->cashier->id, $event->created_by);
    }

    public function test_high_value_cash_drop_requires_manager_verification(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '6000.0000',
            $this->cashier
        );

        $this->branch->update(['cash_drawer_limit' => 5000.00]);

        // Greater than threshold (5000.01)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: high-value cash drop requires manager approval.');

        $this->cashDropService->recordCashDrop(
            $shift,
            $this->cashier,
            '5000.0100',
            'VAULT_DROP',
            'Over threshold drop'
        );
    }

    public function test_cashier_cannot_self_approve_high_value_cash_drop(): void
    {
        // Cashier has manager role for this test (to check self approval guard)
        $this->cashier->assignRole(\App\Models\Role::where('name', 'Branch Manager')->first());

        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '6000.0000',
            $this->cashier
        );

        $this->branch->update(['cash_drawer_limit' => 5000.00]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Security Block: Cashiers cannot approve their own high-value cash drop.');

        $this->cashDropService->recordCashDrop(
            $shift,
            $this->cashier,
            '5000.0100',
            'VAULT_DROP',
            'Self drop over limit',
            $this->cashier->email,
            'password' // Cashier password is 'password' from factory
        );
    }

    public function test_high_value_drop_created_with_manager_actor_after_verification(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '6000.0000',
            $this->cashier
        );

        $this->branch->update(['cash_drawer_limit' => 5000.00]);

        $event = $this->cashDropService->recordCashDrop(
            $shift,
            $this->cashier,
            '5000.0100',
            'VAULT_DROP',
            'Approved by supervisor',
            'manager@test.com',
            'manager123'
        );

        $this->assertInstanceOf(\App\Models\CashDrawerEvent::class, $event);
        $this->assertEquals($this->manager->id, $event->created_by);
    }
}
