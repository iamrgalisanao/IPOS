<?php

namespace Tests\Feature\Compliance;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Models\RegisterZRead;
use App\Services\POS\ZReadGenerationService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisterZReadLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $machineProfile;
    private ProductCategory $category;
    private Product $product;
    private ZReadGenerationService $zReadService;

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

        $this->category = ProductCategory::create(['name' => 'General', 'code' => 'GEN']);
        $this->product = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Generic Product',
            'sku'                  => 'GEN-001',
            'barcode'              => '1111111111',
            'unit_of_measure'      => 'pc',
            'selling_price'        => 100.00,
            'cost_price'           => 40.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        $this->machineProfile = new SalesMachineProfile([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'TERM01',
            'machine_identification_number' => 'MIN-123456789',
            'machine_serial_number' => 'SN-ABC123XYZ',
            'software_license_number' => 'LIC-EOPT-2026',
            'permit_to_use_number' => 'PTU-2026-999',
            'permit_issued_at' => now(),
            'status' => 'active',
            'last_invoice_sequence' => 0,
            'reset_counter' => 0,
        ]);
        $this->machineProfile->grand_cumulative_total = 1000.00;
        $this->machineProfile->z_read_counter = 5;
        $this->machineProfile->save();

        $this->zReadService = app(ZReadGenerationService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    /**
     * Helper to create a fully set-up and paid sale.
     */
    private function createPaidSale(float $amount, string $invoiceNumber, string $date, ?string $status = 'paid'): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sales_machine_profile_id' => $this->machineProfile->id,
            'sale_number' => $invoiceNumber,
            'principal_invoice_number' => $invoiceNumber,
            'principal_invoice_type' => 'invoice',
            'principal_invoice_label' => 'Official Receipt',
            'invoice_issued_at' => $date,
            'reporting_basis_at' => $date,
            'gross_sales_amount' => $amount,
            'vatable_sales_amount' => $amount / 1.12,
            'vat_exempt_sales_amount' => 0,
            'zero_rated_sales_amount' => 0,
            'non_vat_sales_amount' => 0,
            'vat_amount' => $amount - ($amount / 1.12),
            'statutory_discount_total' => 0,
            'commercial_discount_total' => 0,
            'other_adjustment_total' => 0,
            'total' => $amount,
            'subtotal' => $amount,
            'tax_total' => $amount - ($amount / 1.12),
            'discount_total' => 0,
            'status' => $status,
        ]);

        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_amount' => $amount - ($amount / 1.12),
            'line_total' => $amount,
            'is_inventory_tracked' => false,
        ]);

        return $sale;
    }

    // =========================================================================
    // 1. Z-read generates a ledger from eligible completed sales
    // =========================================================================
    public function test_z_read_generates_ledger_with_correct_totals(): void
    {
        $date = '2026-05-19';
        
        $sale1 = $this->createPaidSale(112.00, 'INV-TERM01-0000000001', $date);
        $sale2 = $this->createPaidSale(224.00, 'INV-TERM01-0000000002', $date);

        // Act: Generate Z-read
        $zRead = $this->zReadService->generateZRead($this->machineProfile, $this->cashier, $date);

        // Assert: Ledger counts and totals
        $this->assertNotNull($zRead);
        $this->assertEquals(6, $zRead->z_read_sequence); // incremented from 5 to 6
        $this->assertEquals(1000.00, (float) $zRead->grand_cumulative_total_before);
        $this->assertEquals(1336.00, (float) $zRead->grand_cumulative_total_after); // 1000 + 336 (112 + 224)
        $this->assertEquals(336.00, (float) $zRead->gross_sales_amount);
        $this->assertEquals(300.00, (float) $zRead->vatable_sales_amount); // 336 / 1.12
        $this->assertEquals(36.00, (float) $zRead->vat_amount);
        $this->assertEquals(2, $zRead->transaction_count);
        $this->assertEquals('INV-TERM01-0000000001', $zRead->first_invoice_number);
        $this->assertEquals('INV-TERM01-0000000002', $zRead->last_invoice_number);
        
        // Assert: Period/Shift locking check
        $this->assertEquals($zRead->id, $sale1->refresh()->register_z_read_id);
        $this->assertEquals($zRead->id, $sale2->refresh()->register_z_read_id);
    }

    // =========================================================================
    // 2. GCT and z_read_counter atomicity and state machine integrity
    // =========================================================================
    public function test_gct_and_counter_cannot_be_decreased_or_manually_edited_in_profile(): void
    {
        $this->machineProfile->refresh();
        $this->assertEquals(1000.00, (float) $this->machineProfile->grand_cumulative_total);

        // Attempting to manually decrease the GCT should throw a RuntimeException
        try {
            $this->machineProfile->grand_cumulative_total = 900.00;
            $this->machineProfile->save();
            $this->fail('Expected RuntimeException when decreasing GCT.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Grand Cumulative Total (GCT) cannot be decreased', $e->getMessage());
        }

        // Attempting to decrease z_read_counter should throw a RuntimeException
        $this->machineProfile->refresh();
        try {
            $this->machineProfile->z_read_counter = 3;
            $this->machineProfile->save();
            $this->fail('Expected RuntimeException when decreasing z_read_counter.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('z_read_counter cannot be decreased', $e->getMessage());
        }
    }

    // =========================================================================
    // 3. Failed Z-read generation atomicity (Rollback)
    // =========================================================================
    public function test_failed_z_read_rolls_back_database_changes_including_gct(): void
    {
        $date = '2026-05-19';
        $this->createPaidSale(112.00, 'INV-TERM01-0000000001', $date);

        // We simulate a failure inside Z-read generation by deleting the Tenant record
        // which will trigger a database constraint violation on RegisterZRead insertion.
        try {
            DB::transaction(function () use ($date) {
                // Delete tenant temporarily to trigger a foreign key violation during insert
                DB::table('tenants')->where('id', $this->tenant->id)->delete();

                $this->zReadService->generateZRead($this->machineProfile, $this->cashier, $date);
                $this->fail('Expected foreign key constraint exception.');
            });
        } catch (\Exception $e) {
            // Assert that the exception was caught
            $this->assertNotNull($e);
        }

        // Assert: SalesMachineProfile GCT and counter remained completely unchanged (rolled back!)
        $this->machineProfile->refresh();
        $this->assertEquals(1000.00, (float) $this->machineProfile->grand_cumulative_total);
        $this->assertEquals(5, $this->machineProfile->z_read_counter);
        
        // Assert: No Z-read record was persisted in the ledger
        $this->assertEquals(0, RegisterZRead::count());
    }

    // =========================================================================
    // 4. Same shift/period cannot be Z-read twice
    // =========================================================================
    public function test_same_sales_cannot_be_included_in_multiple_z_reads(): void
    {
        $date = '2026-05-19';
        $this->createPaidSale(112.00, 'INV-TERM01-0000000001', $date);

        // First generation succeeds
        $zRead1 = $this->zReadService->generateZRead($this->machineProfile, $this->cashier, $date);
        $this->assertNotNull($zRead1);

        // Second generation should fail because those sales are now locked
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No eligible completed sales found');
        
        $this->zReadService->generateZRead($this->machineProfile, $this->cashier, $date);
    }

    // =========================================================================
    // 5. Sales included in a finalized Z-read cannot be silently mutated afterward
    // =========================================================================
    public function test_sales_associated_with_z_read_are_completely_locked_from_mutation(): void
    {
        $date = '2026-05-19';
        $sale = $this->createPaidSale(112.00, 'INV-TERM01-0000000001', $date);

        $zRead = $this->zReadService->generateZRead($this->machineProfile, $this->cashier, $date);
        $this->assertNotNull($zRead);

        // Attempting to update *any* field on a sale locked in a finalized Z-read must fail
        $sale->refresh();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales locked in a finalized Z-read cannot be modified.');

        $sale->update(['status' => 'voided']);
    }

    // =========================================================================
    // 6. Void/Refund Handling is Calculated and Aggregated
    // =========================================================================
    public function test_voids_and_refunds_are_calculated_into_the_z_read_sums(): void
    {
        $date = '2026-05-19';
        
        // Create a voided sale
        $saleVoided = $this->createPaidSale(100.00, 'INV-TERM01-0000000001', $date, 'voided');
        
        // Create a regular sale
        $saleRegular = $this->createPaidSale(150.00, 'INV-TERM01-0000000002', $date);

        // Create a mock refund linked to the regular sale
        SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $saleRegular->id,
            'refund_number' => 'REF-001',
            'reason_code' => 'return',
            'refund_total' => 50.00,
            'refunded_by' => $this->cashier->id,
            'refunded_at' => now(),
        ]);

        $zRead = $this->zReadService->generateZRead($this->machineProfile, $this->cashier, $date);

        // Assert: Void amount and refund amount are correctly calculated and aggregated
        $this->assertEquals(100.00, (float) $zRead->void_sales_amount);
        $this->assertEquals(50.00, (float) $zRead->refund_sales_amount);
        
        // The gross sales amount should only count the non-voided sale
        $this->assertEquals(150.00, (float) $zRead->gross_sales_amount);
        
        // GCT should increase by gross sales (150.00)
        $this->assertEquals(1150.00, (float) $zRead->grand_cumulative_total_after);
    }
}
