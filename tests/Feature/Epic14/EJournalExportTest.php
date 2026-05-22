<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleReceiptPrint;
use App\Models\SalesMachineProfile;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EJournalExportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $branchManager;
    protected User $cashier;
    protected SalesMachineProfile $machineProfile;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);
        
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branchA);

        $this->branchManager = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->branchManager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->branchManager->assignToBranch($this->branchA);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branchA);

        $this->machineProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'profile_code' => 'TERM01',
            'machine_identification_number' => 'MIN-123456789',
            'machine_serial_number' => 'SN-ABC123XYZ',
            'software_license_number' => 'LIC-EOPT-2026',
            'permit_to_use_number' => 'PTU-2026-999',
            'permit_issued_at' => now(),
            'status' => 'active',
            'last_invoice_sequence' => 0,
            'grand_cumulative_total' => 0.00,
            'reset_counter' => 0,
            'z_read_counter' => 0,
        ]);
        
        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_from_ejournal_export(): void
    {
        $this->get(route('reports.tax.export.ejournal'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_cannot_access_ejournal_export(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.ejournal'))
            ->assertForbidden();
    }

    public function test_authorized_users_can_download_ejournal_with_correct_headers(): void
    {
        $dateFrom = now()->toDateString();
        $dateTo = now()->toDateString();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.ejournal', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ipos-electronic-journal-'.$dateFrom.'-to-'.$dateTo.'.txt"');
        
        $content = $response->getContent();
        $this->assertStringContainsString('Timestamp|Record Type|Invoice Number|Cashier|Gross Amount|VATable Sales', $content);
    }

    public function test_ejournal_export_enforces_branch_scope(): void
    {
        // Branch manager for Branch A should not be able to export Branch B
        $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.ejournal', [
                'branch_id' => $this->branchB->id,
            ]))
            ->assertNotFound();

        // Branch manager for Branch A should be able to export Branch A
        $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.ejournal', [
                'branch_id' => $this->branchA->id,
            ]))
            ->assertOk();
    }

    public function test_ejournal_export_enforces_tenant_isolation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);
        
        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.ejournal', [
                'branch_id' => $otherBranch->id,
            ]))
            ->assertNotFound();
    }

    public function test_ejournal_contains_various_records_and_hashes(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        app(BranchContext::class)->setBranch($this->branchA);

        // 1. Create a regular production completed sale
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'user_id' => $this->cashier->id,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => 'SAL-001',
            'status' => 'completed',
            'subtotal' => 100.0000,
            'tax_total' => 12.0000,
            'discount_total' => 0.0000,
            'total' => 112.0000,
            'gross_sales_amount' => 100.0000,
            'vatable_sales_amount' => 100.0000,
            'vat_amount' => 12.0000,
            'sales_machine_profile_id' => $this->machineProfile->id,
            'principal_invoice_number' => 'INV-TERM01-0000000001',
            'invoice_issued_at' => now(),
            'is_training_mode' => false,
        ]);

        // 2. Create a training mode sale
        $trainSale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'user_id' => $this->cashier->id,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => 'SAL-002',
            'status' => 'completed',
            'subtotal' => 50.0000,
            'tax_total' => 0.0000,
            'discount_total' => 0.0000,
            'total' => 50.0000,
            'gross_sales_amount' => 50.0000,
            'vatable_sales_amount' => 0.0000,
            'vat_amount' => 0.0000,
            'sales_machine_profile_id' => $this->machineProfile->id,
            'principal_invoice_number' => 'TRAIN-INV-TERM01-A9B8C7D6E5',
            'invoice_issued_at' => now(),
            'is_training_mode' => true,
        ]);

        // 3. Create a refund
        $refund = SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'refund_number' => 'REF-001',
            'reason_code' => 'return',
            'refund_total' => 50.0000,
            'refunded_by' => $this->cashier->id,
            'refunded_at' => now(),
        ]);

        // 4. Create a reprint
        $reprint = SaleReceiptPrint::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'user_id' => $this->cashier->id,
            'print_sequence' => 2,
            'is_reprint' => true,
            'reprint_reason' => 'customer requested',
            'printed_at' => now(),
        ]);

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.ejournal'));

        $response->assertOk();
        $content = $response->getContent();

        // Should contain standard SALE
        $this->assertStringContainsString('SALE|INV-TERM01-0000000001|', $content);

        // Should contain TRAINING_SALE
        $this->assertStringContainsString('TRAINING_SALE|TRAIN-INV-TERM01-A9B8C7D6E5|', $content);

        // Should contain REFUND
        $this->assertStringContainsString('REFUND|REF-001|', $content);

        // Should contain REPRINT
        $this->assertStringContainsString('REPRINT|INV-TERM01-0000000001|', $content);

        // Verify hashes: Let's split content into lines and check that all rows (except header) end with a 64-char hex SHA-256 hash.
        $lines = explode("\n", trim($content));
        $this->assertGreaterThan(1, count($lines));
        
        // Assert header has NO hash column value, but has the column header
        $this->assertStringContainsString('TAMPER-EVIDENT HASH', $lines[0]);

        for ($i = 1; $i < count($lines); $i++) {
            $parts = explode('|', $lines[$i]);
            $hash = end($parts);
            $this->assertEquals(64, strlen($hash), "Row {$i} does not end with a valid SHA-256 hash: " . $lines[$i]);
            
            // Recompute to prove tamper-evident property
            array_pop($parts);
            $lineWithoutHash = implode('|', $parts);
            $expectedHash = hash_hmac('sha256', $lineWithoutHash, 'ipos_ejournal_compliance_key');
            $this->assertEquals($expectedHash, $hash, "Tamper-evident hash validation failed for row: " . $lines[$i]);
        }
    }
}
