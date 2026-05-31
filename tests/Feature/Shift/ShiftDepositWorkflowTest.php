<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftDepositRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\Shift\ShiftService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShiftDepositWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $manager;
    protected ShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        // Seed RBAC permissions and roles
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->cashier->assignToBranch($this->branch);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'manager@test.com',
            'password' => Hash::make('manager123'),
            'status' => 'active',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $this->manager->assignToBranch($this->branch);

        $this->shiftService = app(ShiftService::class);
    }

    public function test_shift_approval_creates_exactly_one_deposit_record(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        // Record a cash drop event
        $this->shiftService->recordDrawerEvent(
            $shift,
            $this->cashier,
            'cash_drop',
            '200.0000',
            'mid_day_drop',
            'Drop cash to vault'
        );

        // Submit closing
        $this->shiftService->submitClosingCount(
            $shift,
            $this->cashier,
            '800.0000',
            'Closing note',
            null,
            ['1000' => 0]
        );

        $this->assertEquals(Shift::STATUS_CLOSING_SUBMITTED, $shift->status);

        // Call the approve route with deposit details
        $response = $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Approve note',
                'deposit_amount' => 800,
                'variance_explanation' => 'All matched',
                'bank_name' => 'BDO',
                'reference_number' => 'REF123',
                'deposited_at' => now()->toDateString(),
            ]);

        $response->assertStatus(302); // Redirect back on success
        
        $shift->refresh();
        $this->assertEquals(Shift::STATUS_APPROVED, $shift->status);

        // Verify ShiftDepositRecord creation
        $deposit = $shift->depositRecord;
        $this->assertNotNull($deposit);
        $this->assertEquals('800.0000', $deposit->deposit_amount);
        $this->assertEquals('800.0000', $deposit->expected_cash_amount);
        $this->assertEquals('800.0000', $deposit->counted_cash_amount);
        $this->assertEquals('200.0000', $deposit->cash_drop_total);
        $this->assertEquals('0.0000', $deposit->variance_amount);
        $this->assertEquals('All matched', $deposit->variance_explanation);
        $this->assertEquals('BDO', $deposit->bank_name);
        $this->assertEquals('REF123', $deposit->reference_number);
    }

    public function test_duplicate_approval_does_not_create_another_deposit_record(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $this->shiftService->submitClosingCount($shift, $this->cashier, '1000.0000');

        // Approve it first time
        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Approved',
                'deposit_amount' => 1000,
            ]);

        $this->assertEquals(1, ShiftDepositRecord::where('shift_id', $shift->id)->count());

        // Approve it second time
        $response = $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Approved again',
                'deposit_amount' => 1000,
            ]);

        // Should return redirect with error (or be blocked before service execution)
        $response->assertStatus(302);
        $response->assertSessionHas('error', 'A deposit record already exists for this shift.');

        $this->assertEquals(1, ShiftDepositRecord::where('shift_id', $shift->id)->count());
    }

    public function test_deposit_record_captures_variance_explanation_when_variance_exists(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        // Submit closing count with variance (expected 1000, counted 950, variance -50)
        $this->shiftService->submitClosingCount($shift, $this->cashier, '950.0000');

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Under count',
                'deposit_amount' => 950,
                'variance_explanation' => 'Cashier short on loose change',
            ]);

        $deposit = $shift->refresh()->depositRecord;
        $this->assertNotNull($deposit);
        $this->assertEquals('1000.0000', $deposit->expected_cash_amount);
        $this->assertEquals('950.0000', $deposit->counted_cash_amount);
        $this->assertEquals('-50.0000', $deposit->variance_amount);
        $this->assertEquals('Cashier short on loose change', $deposit->variance_explanation);
    }

    public function test_deposit_record_is_immutable(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );
        $this->shiftService->submitClosingCount($shift, $this->cashier, '1000.0000');

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Approved',
                'deposit_amount' => 1000,
            ]);

        $deposit = $shift->refresh()->depositRecord;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift deposit records are immutable and cannot be updated.');
        $deposit->update(['deposit_amount' => '2000.0000']);
    }

    public function test_deposit_record_cannot_be_deleted(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );
        $this->shiftService->submitClosingCount($shift, $this->cashier, '1000.0000');

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Approved',
                'deposit_amount' => 1000,
            ]);

        $deposit = $shift->refresh()->depositRecord;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift deposit records are immutable and cannot be deleted.');
        $deposit->delete();
    }

    public function test_cross_tenant_shift_approval_is_blocked(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );
        $this->shiftService->submitClosingCount($shift, $this->cashier, '1000.0000');

        // Create manager from another tenant
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        
        // Temporarily set tenant context to other tenant to create user without cross-tenant block
        app(TenantContext::class)->setTenant($otherTenant);
        (new RbacSeeder())->seedForTenant($otherTenant);
        app(TenantContext::class)->setTenant($otherTenant);

        $otherManager = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'email' => 'other_manager@test.com',
            'password' => Hash::make('manager123'),
            'status' => 'active',
        ]);
        $otherManager->assignRole(Role::where('name', 'Branch Manager')->first());

        // Restore context to original tenant
        app(TenantContext::class)->setTenant($this->tenant);

        // Attempt approval
        $response = $this->actingAs($otherManager)
            ->withHeader('X-Tenant-ID', $otherTenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('shifts.approve', ['shift' => $shift->id]), [
                'manager_notes' => 'Approved cross tenant',
                'deposit_amount' => 1000,
            ]);

        // Route model binding scopes to active tenant, so shift should not be found (404)
        $response->assertStatus(404);
    }
}
