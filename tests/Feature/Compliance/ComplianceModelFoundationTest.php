<?php

namespace Tests\Feature\Compliance;

use App\Models\Branch;
use App\Models\RegisterZRead;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReceiptPrint;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComplianceModelFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $machineProfile;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch);

        $this->machineProfile = new SalesMachineProfile([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'MAIN-POS-01',
            'machine_identification_number' => 'MIN-999',
            'machine_serial_number' => 'SER-999',
            'software_license_number' => 'LIC-999',
            'permit_to_use_number' => 'PTU-999',
            'status' => 'active',
            'reset_counter' => 1,
            'terminal_identifier' => 'TERM-01',
        ]);
        $this->machineProfile->grand_cumulative_total = 125000.5000;
        $this->machineProfile->z_read_counter = 12;
        $this->machineProfile->save();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    public function test_sales_machine_profile_compliance_fields_persist_and_cast(): void
    {
        $profile = SalesMachineProfile::where('profile_code', 'MAIN-POS-01')->firstOrFail();

        $this->assertEquals(125000.5000, $profile->grand_cumulative_total);
        $this->assertEquals(1, $profile->reset_counter);
        $this->assertEquals(12, $profile->z_read_counter);
        $this->assertEquals('TERM-01', $profile->terminal_identifier);

        // Cast assertions
        $this->assertIsFloat((float) $profile->grand_cumulative_total);
        $this->assertIsInt($profile->reset_counter);
        $this->assertIsInt($profile->z_read_counter);
    }

    public function test_sale_receipt_print_audit_log_creation(): void
    {
        // 1. Create a dummy sale
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => 'INV-000001',
            'status' => 'confirmed',
            'subtotal' => 100.00,
            'tax_total' => 10.71,
            'discount_total' => 0.00,
            'total' => 100.00,
            'gross_sales_amount' => 100.00,
            'vatable_sales_amount' => 89.29,
            'vat_amount' => 10.71,
            'compliance_version' => 'EPIC14_V1',
            'confirmed_at' => now(),
        ]);

        // 2. Create receipt print record
        $print = SaleReceiptPrint::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'user_id' => $this->cashier->id,
            'print_sequence' => 2,
            'is_reprint' => true,
            'reprint_reason' => 'Printer jammed on first attempt',
            'printed_at' => now(),
            'metadata' => ['device' => 'iPad-POS-1', 'printer' => 'Epson-T88'],
        ]);

        $this->assertNotNull($print->id);
        $this->assertTrue(Str::isUuid($print->id));
        $this->assertEquals(2, $print->print_sequence);
        $this->assertTrue($print->is_reprint);
        $this->assertEquals('Printer jammed on first attempt', $print->reprint_reason);
        $this->assertEquals('Epson-T88', $print->metadata['printer']);

        // Assert relationships
        $this->assertEquals($sale->id, $print->sale->id);
        $this->assertEquals($this->cashier->id, $print->user->id);
    }

    public function test_register_z_read_ledger_creation(): void
    {
        $zRead = RegisterZRead::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $this->machineProfile->id,
            'user_id' => $this->cashier->id,
            'z_read_sequence' => 13,
            'z_read_date' => '2026-05-19',
            'grand_cumulative_total_before' => 125000.5000,
            'grand_cumulative_total_after' => 135000.7500,
            'gross_sales_amount' => 10000.2500,
            'vatable_sales_amount' => 8928.7900,
            'vat_exempt_sales_amount' => 0.0000,
            'zero_rated_sales_amount' => 0.0000,
            'non_vat_sales_amount' => 0.0000,
            'vat_amount' => 1071.4600,
            'statutory_discount_total' => 0.0000,
            'commercial_discount_total' => 0.0000,
            'other_adjustment_total' => 0.0000,
            'void_sales_amount' => 150.0000,
            'refund_sales_amount' => 0.0000,
            'transaction_count' => 85,
            'reset_counter' => 1,
            'first_invoice_number' => 'INV-000100',
            'last_invoice_number' => 'INV-000185',
            'reporting_basis_at' => now(),
            'is_training_mode' => false,
            'raw_journal_payload' => 'E-Journal Block Content...',
            'tamper_evident_hash' => 'hash123xyz',
        ]);

        $this->assertNotNull($zRead->id);
        $this->assertTrue(Str::isUuid($zRead->id));
        $this->assertEquals(13, $zRead->z_read_sequence);
        $this->assertEquals('2026-05-19', $zRead->z_read_date->format('Y-m-d'));
        $this->assertEquals(125000.5000, $zRead->grand_cumulative_total_before);
        $this->assertEquals(135000.7500, $zRead->grand_cumulative_total_after);
        $this->assertEquals(85, $zRead->transaction_count);
        $this->assertFalse($zRead->is_training_mode);
        $this->assertEquals('hash123xyz', $zRead->tamper_evident_hash);

        // Relationships
        $this->assertEquals($this->machineProfile->id, $zRead->salesMachineProfile->id);
        $this->assertEquals($this->cashier->id, $zRead->user->id);
    }
}
