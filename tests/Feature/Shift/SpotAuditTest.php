<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\SpotAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shift\ShiftService;
use App\Services\Shift\SpotAuditService;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SpotAuditTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftService $shiftService;
    protected SpotAuditService $spotAuditService;
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
        $this->spotAuditService = app(SpotAuditService::class);
    }

    public function test_manager_can_perform_spot_audit_with_valid_credentials(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $denominations = ['1000' => 1];

        $audit = $this->spotAuditService->performSpotAudit(
            $shift,
            'manager@test.com',
            'manager123',
            '1000.0000',
            $denominations,
            'Surprise audit notes'
        );

        $this->assertInstanceOf(SpotAudit::class, $audit);
        $this->assertEquals($shift->id, $audit->shift_id);
        $this->assertEquals($this->manager->id, $audit->manager_id);
        $this->assertEquals('1000.0000', $audit->expected_cash_amount);
        $this->assertEquals('1000.0000', $audit->counted_cash_amount);
        $this->assertEquals('0.0000', $audit->variance_amount);
        $this->assertDatabaseHas('spot_audits', [
            'id' => $audit->id,
            'audit_notes' => 'Surprise audit notes'
        ]);
    }

    public function test_spot_audit_requires_correct_password(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid manager credentials.');

        $this->spotAuditService->performSpotAudit(
            $shift,
            'manager@test.com',
            'wrongpassword',
            '1000.0000',
            ['1000' => 1]
        );
    }

    public function test_inactive_manager_cannot_approve_spot_audit(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $this->manager->update(['status' => 'inactive']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid manager credentials.');

        $this->spotAuditService->performSpotAudit(
            $shift,
            'manager@test.com',
            'manager123',
            '1000.0000',
            ['1000' => 1]
        );
    }

    public function test_manager_from_another_tenant_cannot_approve_spot_audit(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $otherManager = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'email' => 'other@manager.com',
            'password' => Hash::make('manager123'),
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        // Note: active tenant context remains $this->tenant

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid manager credentials.');

        $this->spotAuditService->performSpotAudit(
            $shift,
            'other@manager.com',
            'manager123',
            '1000.0000',
            ['1000' => 1]
        );
    }

    public function test_manager_without_permission_cannot_approve_spot_audit(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $simpleUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'simple@test.com',
            'password' => Hash::make('password123'),
        ]);
        // No role/permissions

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: manager missing required permissions.');

        $this->spotAuditService->performSpotAudit(
            $shift,
            'simple@test.com',
            'password123',
            '1000.0000',
            ['1000' => 1]
        );
    }

    public function test_spot_audit_excludes_non_cash_payments_from_expected_cash(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        // Create a cash payment method
        $cashMethod = \App\Models\PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Method',
            'code' => 'cash',
            'type' => 'cash',
            'is_default' => true,
            'status' => 'active',
        ]);

        // Create a card payment method
        $cardMethod = \App\Models\PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Card Method',
            'code' => 'card',
            'type' => 'card',
            'is_default' => false,
            'status' => 'active',
        ]);

        $profile = \App\Models\SalesMachineProfile::create([
            'tenant_id'                            => $this->tenant->id,
            'branch_id'                            => $this->branch->id,
            'profile_code'                         => 'TERM-01',
            'machine_identification_number'        => 'MIN-001',
            'machine_serial_number'                => 'SER-001',
            'software_license_number'              => 'LIC-001',
            'permit_to_use_number'                 => 'PTU-001',
            'authority_to_generate_control_number' => 'ATG-001',
            'supplier_name'                        => 'Supplier',
            'supplier_tin'                         => '123-456-789-000',
            'supplier_branch_code'                 => '00001',
            'supplier_accreditation_number'        => 'ACC-001',
            'status'                               => 'active',
            'offline_sequence_prefix'              => 'INV-',
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
        ]);

        $sale = \App\Models\Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => 500.00,
            'total' => 500.00,
            'status' => 'completed',
        ]);

        // Card payment (should be excluded)
        \App\Models\SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'shift_id' => $shift->id,
            'payment_method_id' => $cardMethod->id,
            'payment_type' => 'card',
            'amount' => '300.0000',
            'status' => 'recorded',
            'paid_at' => now(),
        ]);

        // Cash payment (should be included)
        \App\Models\SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'payment_type' => 'cash',
            'amount' => '200.0000',
            'status' => 'recorded',
            'paid_at' => now(),
        ]);

        $expected = $this->shiftService->calculateExpectedCash($shift);
        // Expected should be 1000 (opening) + 200 (cash payment) = 1200
        $this->assertEquals('1200.0000', $expected);

        $audit = $this->spotAuditService->performSpotAudit(
            $shift,
            'manager@test.com',
            'manager123',
            '1200.0000',
            ['1000' => 1, '200' => 1]
        );

        $this->assertEquals('1200.0000', $audit->expected_cash_amount);
    }

    public function test_spot_audit_records_are_immutable(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $audit = $this->spotAuditService->performSpotAudit(
            $shift,
            'manager@test.com',
            'manager123',
            '1000.0000',
            ['1000' => 1]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Spot audit records are immutable and cannot be updated.');
        $audit->update(['audit_notes' => 'Hack']);
    }

    public function test_spot_audit_records_cannot_be_deleted(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $audit = $this->spotAuditService->performSpotAudit(
            $shift,
            'manager@test.com',
            'manager123',
            '1000.0000',
            ['1000' => 1]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Spot audit records are immutable and cannot be deleted.');
        $audit->delete();
    }
}
