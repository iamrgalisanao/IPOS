<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PriorPeriodAdjustment;
use App\Models\ReconciliationDiscrepancyLog;
use App\Models\RegisterZRead;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesMachineProfile;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LateSyncReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $admin;
    protected SalesMachineProfile $profile;
    protected SettlementPeriod $settlementPeriod;
    protected Product $product;
    protected PaymentMethod $paymentMethod;
    protected OfflineSyncBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\Inventory\ProcessSaleInventoryDeductionJob::class]);

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->admin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->admin->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        // Create active open settlement period
        $this->settlementPeriod = SettlementPeriod::create([
            'tenant_id'       => $this->tenant->id,
            'branch_id'       => $this->branch->id,
            'period_start_at' => Carbon::parse('2026-05-30 08:00:00'),
            'period_end_at'   => Carbon::parse('2026-05-30 20:00:00'),
            'status'          => SettlementPeriod::STATUS_OPEN,
            'opened_by'       => $this->admin->id,
            'opened_at'       => now(),
        ]);

        $this->profile = SalesMachineProfile::create([
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
        $this->profile->grand_cumulative_total = 1000.00;
        $this->profile->save();

        $this->batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-001',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
            'submitted_import_count'   => 1,
        ]);

        $taxCategory = \App\Models\TaxCategory::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT',
            'code' => 'VAT',
            'tax_type' => 'vatable',
            'rate' => 12.00,
            'status' => 'active',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_category_id' => $taxCategory->id,
            'selling_price' => 100.00,
            'is_inventory_tracked' => true,
        ]);

        \App\Models\BranchInventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 50,
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'cash',
            'status' => 'active',
        ]);
    }

    /**
     * Test that a late-synced sale shifts its reporting_basis_at and creates a PriorPeriodAdjustment.
     */
    public function test_tc_33_01_late_sync_shifts_basis_and_creates_adjustment()
    {
        // 1. Create a closed Z-Read for a past date: '2026-05-28'
        $closedZRead = RegisterZRead::create([
            'tenant_id'                     => $this->tenant->id,
            'branch_id'                     => $this->branch->id,
            'sales_machine_profile_id'      => $this->profile->id,
            'user_id'                       => $this->admin->id,
            'z_read_sequence'               => 1,
            'z_read_date'                   => '2026-05-28',
            'grand_cumulative_total_before' => 900.00,
            'grand_cumulative_total_after'  => 1000.00,
            'gross_sales_amount'            => 100.00,
            'vatable_sales_amount'          => 89.29,
            'vat_exempt_sales_amount'       => 0.00,
            'zero_rated_sales_amount'       => 0.00,
            'non_vat_sales_amount'          => 0.00,
            'vat_amount'                    => 10.71,
            'statutory_discount_total'      => 0.00,
            'commercial_discount_total'     => 0.00,
            'other_adjustment_total'        => 0.00,
            'void_sales_amount'             => 0.00,
            'refund_sales_amount'           => 0.00,
            'transaction_count'             => 1,
            'reset_counter'                 => 0,
            'first_invoice_number'          => 'INV-001',
            'last_invoice_number'           => 'INV-001',
            'reporting_basis_at'            => Carbon::parse('2026-05-28 20:00:00'),
            'is_training_mode'              => false,
            'tamper_evident_hash'           => 'dummy_hash',
        ]);

        // 2. Setup an import with a transaction timestamp in the closed period ('2026-05-28 14:00:00')
        $rawPayload = [
            'user_id'             => $this->admin->id,
            'client_request_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'submitted_at'        => '2026-05-28T14:00:00Z',
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount'            => '100.00',
                ]
            ],
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 1,
                    'unit_price' => 100.00,
                ]
            ],
            'client_subtotal'  => '100.0000',
            'client_tax_total' => '10.7143',
            'client_total'     => '100.0000',
        ];

        $serverRecalc = [
            'server_subtotal'  => '100.0000',
            'server_tax_total' => '10.7143',
            'server_total'     => '100.0000',
            'items' => [
                [
                    'product_id'           => $this->product->id,
                    'product_name'         => 'Test Product',
                    'sku'                  => 'SKU-01',
                    'barcode'              => 'BAR-01',
                    'unit_of_measure'      => 'piece',
                    'quantity'             => '1.0000',
                    'selling_price'        => '100.0000',
                    'subtotal'             => '100.0000',
                    'tax_category_id'      => $this->product->tax_category_id,
                    'tax_type'             => 'vatable',
                    'tax_bucket'           => SaleItem::TAX_BUCKET_VATABLE,
                    'tax_rate'             => '12.0000',
                    'tax_amount'           => '10.7143',
                    'tax_snapshot'         => [],
                    'is_inventory_tracked' => true,
                ]
            ],
            'client_submitted' => [
                'client_subtotal'  => '100.0000',
                'client_tax_total' => '10.7143',
                'client_total'     => '100.0000',
            ]
        ];

        $import = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $this->batch->id,
            'offline_sequence_number'  => 'INV-002',
            'payload_hash'             => hash('sha256', 'payload-content'),
            'raw_payload'              => $rawPayload,
            'server_recalculation'     => $serverRecalc,
            'status'                   => 'server_verified',
            'submitted_at'             => Carbon::parse('2026-05-28 14:00:00'),
        ]);

        $reconciliationService = app(OfflineReconciliationService::class);
        $sale = $reconciliationService->reconcileImport($import);

        // 3. Asserts
        $this->assertNotNull($sale);
        
        // Assert reporting basis is shifted to the settlement period's start time
        $this->assertEquals(
            Carbon::parse('2026-05-30 08:00:00')->toDateTimeString(),
            $sale->reporting_basis_at->toDateTimeString()
        );

        // Assert original transaction timestamp is preserved on sale
        $this->assertEquals(
            Carbon::parse('2026-05-28 14:00:00')->toDateTimeString(),
            Carbon::parse($sale->invoice_issued_at)->toDateTimeString()
        );

        // Assert Z-report remains completely unchanged (immutability)
        $closedZRead->refresh();
        $this->assertEquals('dummy_hash', $closedZRead->tamper_evident_hash);
        $this->assertEquals(100.00, (float) $closedZRead->gross_sales_amount);

        // Assert PriorPeriodAdjustment is created in DB
        $adjustment = PriorPeriodAdjustment::where('sale_id', $sale->id)->first();
        $this->assertNotNull($adjustment);
        $this->assertEquals($closedZRead->id, $adjustment->original_register_z_read_id);
        $this->assertEquals($this->settlementPeriod->id, $adjustment->adjusted_into_settlement_period_id);
        $this->assertEquals('posted', $adjustment->status);
        $this->assertEquals(100.00, (float) $adjustment->gross_amount);
        $this->assertEquals(10.7143, (float) $adjustment->vat_amount);
    }

    /**
     * Test that an import with reported_gct mismatched from the server GCT logs a discrepancy.
     */
    public function test_tc_33_02_gct_discrepancy_logs_mismatch()
    {
        $reconciliationService = app(OfflineReconciliationService::class);

        // Send GCT of 1500.00 when profile GCT is 1000.00
        $payload = [
            'batch_reference' => 'BATCH-REF-X',
            'reported_gct'    => 1500.00,
            'imports'         => []
        ];

        $reconciliationService->receiveImportBatch($this->profile, $payload);

        // Assert discrepancy log was created
        $log = ReconciliationDiscrepancyLog::where('sales_machine_profile_id', $this->profile->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(1500.00, (float) $log->reported_gct);
        $this->assertEquals(1000.00, (float) $log->calculated_gct);
        $this->assertEquals(500.00, (float) $log->discrepancy_amount);
        $this->assertEquals('sync', $log->context_type);
    }

    /**
     * Test cross-tenant isolation in retrieving adjustments data.
     */
    public function test_tc_33_03_cross_tenant_isolation()
    {
        // Setup second tenant
        $secondTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($secondTenant);

        $tenantContext = app(TenantContext::class);
        $tenantContext->setTenant($secondTenant);

        $secondBranch = Branch::factory()->create([
            'tenant_id' => $secondTenant->id,
            'status'    => 'active',
        ]);

        $secondProfile = SalesMachineProfile::create([
            'tenant_id'                            => $secondTenant->id,
            'branch_id'                            => $secondBranch->id,
            'profile_code'                         => 'TERM-02',
            'machine_identification_number'        => 'MIN-002',
            'machine_serial_number'                => 'SER-002',
            'software_license_number'              => 'LIC-002',
            'permit_to_use_number'                 => 'PTU-002',
            'authority_to_generate_control_number' => 'ATG-002',
            'supplier_name'                        => 'Supplier 2',
            'supplier_tin'                         => '987-654-321-000',
            'supplier_branch_code'                 => '00002',
            'supplier_accreditation_number'        => 'ACC-002',
            'status'                               => 'active',
            'offline_sequence_prefix'              => 'INV2-',
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
        ]);

        // Create adjustment for second tenant
        $adj2 = PriorPeriodAdjustment::create([
            'tenant_id'                => $secondTenant->id,
            'branch_id'                => $secondBranch->id,
            'sales_machine_profile_id' => $secondProfile->id,
            'original_transaction_at' => now(),
            'original_business_date' => now(),
            'reporting_basis_at' => now(),
            'reconciled_at' => now(),
            'gross_amount' => 200.00,
            'net_amount' => 178.58,
            'vat_amount' => 21.42,
            'status' => 'posted',
        ]);

        // Restore original tenant context
        $tenantContext->setTenant($this->tenant);

        // Create adjustment for first tenant
        $adj1 = PriorPeriodAdjustment::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'original_transaction_at' => now(),
            'original_business_date' => now(),
            'reporting_basis_at' => now(),
            'reconciled_at' => now(),
            'gross_amount' => 100.00,
            'net_amount' => 89.29,
            'vat_amount' => 10.71,
            'status' => 'posted',
        ]);

        // Retrieve data as first tenant admin
        $response = $this->actingAs($this->admin, 'web')
            ->getJson(route('admin.prior-period-adjustments.data'));

        $response->assertStatus(200);
        $adjustments = $response->json('adjustments');

        // Verify only first tenant's adjustment is returned
        $this->assertCount(1, $adjustments);
        $this->assertEquals($adj1->id, $adjustments[0]['id']);
    }
}
