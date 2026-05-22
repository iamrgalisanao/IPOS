<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesMachineProfile;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineSync\OfflineImportRecalculationService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 28.8: Epic 28 Phase 2 Slice D — Offline Import Server Recalculation
 *
 * Test Matrix:
 *   TC-28.8-01 Valid batch with matching client totals becomes server_verified.
 *   TC-28.8-02 Client total mismatch beyond tolerance becomes conflict.
 *   TC-28.8-03 Difference within tolerance becomes server_verified.
 *   TC-28.8-04 Unknown product becomes rejected.
 *   TC-28.8-05 Inactive product becomes rejected.
 *   TC-28.8-06 recalculate() returns structured result.
 *   TC-28.8-07 Vatable inclusive VAT computation is correct.
 *   TC-28.8-08 VAT-exempt computation has zero tax.
 *   TC-28.8-09 No Sale row is created and reconciled_sale_id remains null.
 *   TC-28.8-10 Missing client_total returns 422.
 *   TC-28.8-11 Idempotent replay still returns 200 after server verification.
 *   TC-28.8-12 Mixed batch produces server_verified, conflict, and rejected rows.
 */
class OfflineImportRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected SalesMachineProfile $profile;

    protected Product $vatableProduct;
    protected Product $exemptProduct;
    protected Product $inactiveProduct;

    private const PREFIX = 'INV-T01-';

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status'                => 'active',
            'offline_sales_enabled' => true,
        ]);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id'             => $this->tenant->id,
            'status'                => 'active',
            'offline_sales_enabled' => true,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->profile = SalesMachineProfile::create([
            'tenant_id'                            => $this->tenant->id,
            'branch_id'                            => $this->branch->id,
            'profile_code'                         => 'MAIN-POS',
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
            'offline_sales_enabled'                => null, // inherits
            'offline_sequence_prefix'              => self::PREFIX,
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
        ]);

        // Setup products and tax categories
        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);

        $vatCategory = TaxCategory::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'VAT 12%',
            'code'        => 'VAT_12',
            'tax_type'    => 'vatable',
            'rate'        => 12.00,
            'description' => 'Standard VAT',
        ]);

        $exemptCategory = TaxCategory::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'VAT Exempt',
            'code'        => 'EXEMPT',
            'tax_type'    => 'exempt',
            'rate'        => 0.00,
            'description' => 'Exempt',
        ]);

        $this->vatableProduct = Product::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id'     => $vatCategory->id,
            'selling_price'       => 112.00, // Inclusive VAT -> net 100, tax 12
            'status'              => 'active',
        ]);

        $this->exemptProduct = Product::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id'     => $exemptCategory->id,
            'selling_price'       => 50.00, // Exempt -> net 50, tax 0
            'status'              => 'active',
        ]);

        $this->inactiveProduct = Product::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id'     => $vatCategory->id,
            'selling_price'       => 100.00,
            'status'              => 'inactive',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function postSync(array $payload, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $user ??= $this->cashier;

        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/offline-sync', $payload);
    }

    // =========================================================================
    // TC-28.8-01: Valid batch with matching client totals becomes server_verified.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_01_valid_batch_matching_totals_becomes_server_verified(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-001',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0001',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => $this->vatableProduct->id,
                            'quantity'   => 2,
                            'unit_price' => 112.00,
                        ],
                    ],
                    // 2 * 112 = 224 gross. Net = 200, Tax = 24.
                    'client_subtotal'  => 224.00,
                    'client_tax_total' => 24.00,
                    'client_total'     => 224.00,
                ],
            ],
        ]);

        $response->assertStatus(202);
        $data = $response->json();

        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $data['imports'][0]['status']);
        $this->assertEquals('224.0000', $data['imports'][0]['server_total']);

        $import = OfflineSalesImport::where('offline_sequence_number', self::PREFIX . '0001')->first();
        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $import->status);
        $this->assertNotNull($import->server_recalculation);
        $this->assertNull($import->conflict_notes);
    }

    // =========================================================================
    // TC-28.8-02: Client total mismatch beyond tolerance becomes conflict.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_02_total_mismatch_becomes_conflict(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-002',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0002',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => $this->vatableProduct->id,
                            'quantity'   => 1,
                            'unit_price' => 112.00, // server will compute 112.00 total
                        ],
                    ],
                    'client_subtotal'  => 100.00, // deliberately wrong
                    'client_tax_total' => 0.00,   // deliberately wrong
                    'client_total'     => 100.00, // deliberately wrong
                ],
            ],
        ]);

        $response->assertStatus(202);
        $data = $response->json();

        $this->assertEquals(OfflineSalesImport::STATUS_CONFLICT, $data['imports'][0]['status']);
        $this->assertEquals('112.0000', $data['imports'][0]['server_total']);
        $this->assertStringContainsString('difference=12.00', $data['imports'][0]['conflict_notes']);

        $import = OfflineSalesImport::where('offline_sequence_number', self::PREFIX . '0002')->first();
        $this->assertEquals(OfflineSalesImport::STATUS_CONFLICT, $import->status);
        $this->assertNotNull($import->conflict_notes);
        $this->assertNotNull($import->server_recalculation);
    }

    // =========================================================================
    // TC-28.8-03: Difference within tolerance becomes server_verified.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_03_difference_within_tolerance_is_verified(): void
    {
        config(['offline.recalculation_tolerance' => 0.05]);

        $response = $this->postSync([
            'batch_reference' => 'BATCH-003',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0003',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => $this->vatableProduct->id,
                            'quantity'   => 1,
                            'unit_price' => 112.00, // Server: total=112.00, tax=12.00
                        ],
                    ],
                    // Client totals slightly off but within 0.05
                    'client_subtotal'  => 111.97,
                    'client_tax_total' => 11.98,
                    'client_total'     => 111.97,
                ],
            ],
        ]);

        $response->assertStatus(202);
        $data = $response->json();

        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $data['imports'][0]['status']);
        
        $import = OfflineSalesImport::where('offline_sequence_number', self::PREFIX . '0003')->first();
        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $import->status);
    }

    // =========================================================================
    // TC-28.8-04: Unknown product becomes rejected.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_04_unknown_product_is_rejected(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-004',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0004',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => '00000000-0000-0000-0000-000000000999', // Unknown
                            'quantity'   => 1,
                            'unit_price' => 10.00,
                        ],
                    ],
                    'client_subtotal'  => 10.00,
                    'client_tax_total' => 0.00,
                    'client_total'     => 10.00,
                ],
            ],
        ]);

        $response->assertStatus(202); // Batch accepted
        $data = $response->json();

        $this->assertEquals(OfflineSalesImport::STATUS_REJECTED, $data['imports'][0]['status']);
        $this->assertStringContainsString('product_not_found', $data['imports'][0]['reason']);
        $this->assertEquals(1, $data['failed']);
    }

    // =========================================================================
    // TC-28.8-05: Inactive product becomes rejected.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_05_inactive_product_is_rejected(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-005',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0005',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => $this->inactiveProduct->id,
                            'quantity'   => 1,
                            'unit_price' => 100.00,
                        ],
                    ],
                    'client_subtotal'  => 100.00,
                    'client_tax_total' => 0.00,
                    'client_total'     => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(202);
        $data = $response->json();

        $this->assertEquals(OfflineSalesImport::STATUS_REJECTED, $data['imports'][0]['status']);
        $this->assertStringContainsString('product_not_found', $data['imports'][0]['reason']);
    }

    // =========================================================================
    // TC-28.8-06: recalculate() returns structured result.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_06_recalculate_returns_structured_result(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-006',
            'status'                   => OfflineSyncBatch::STATUS_PROCESSING,
            'submitted_import_count'   => 1,
            'sync_started_at'          => now(),
        ]);

        $import = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '0006',
            'payload_hash'             => 'hash',
            'raw_payload'              => [
                'items' => [
                    ['product_id' => $this->vatableProduct->id, 'quantity' => 1, 'unit_price' => 112.00]
                ],
                'client_subtotal'  => 112.00,
                'client_tax_total' => 12.00,
                'client_total'     => 112.00,
            ],
            'status'                   => OfflineSalesImport::STATUS_PENDING,
            'submitted_at'             => now(),
        ]);

        $service = app(OfflineImportRecalculationService::class);
        $result = $service->recalculate($import);

        $this->assertIsArray($result);
        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $result['status']);
        $this->assertEquals('112.0000', $result['server_subtotal']);
        $this->assertEquals('12.0000', $result['server_tax_total']);
        $this->assertEquals('112.0000', $result['server_total']);
        $this->assertIsArray($result['server_recalculation']);
        $this->assertArrayNotHasKey('conflict_notes', $result); // Since it verified
    }

    // =========================================================================
    // TC-28.8-07: Vatable inclusive VAT computation is correct.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_07_vatable_inclusive_vat_computation(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-007',
            'status'                   => OfflineSyncBatch::STATUS_PROCESSING,
            'submitted_import_count'   => 1,
            'sync_started_at'          => now(),
        ]);

        $import = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '0007',
            'payload_hash'             => 'hash',
            'raw_payload'              => [
                'items' => [
                    ['product_id' => $this->vatableProduct->id, 'quantity' => 2, 'unit_price' => 112.00]
                ],
                'client_subtotal'  => 224.00,
                'client_tax_total' => 24.00,
                'client_total'     => 224.00,
            ],
            'status'                   => OfflineSalesImport::STATUS_PENDING,
            'submitted_at'             => now(),
        ]);

        $service = app(OfflineImportRecalculationService::class);
        $result = $service->recalculate($import);

        $this->assertEquals('224.0000', $result['server_total']);
        $this->assertEquals('24.0000', $result['server_tax_total']);
        
        $item = $result['server_recalculation']['items'][0];
        $this->assertEquals('24.0000', $item['tax_amount']);
        $this->assertEquals('200.0000', $item['tax_snapshot']['net_amount']);
        $this->assertEquals('200.0000', $item['tax_snapshot']['vatable_amount']);
    }

    // =========================================================================
    // TC-28.8-08: VAT-exempt computation has zero tax.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_08_vat_exempt_computation(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-008',
            'status'                   => OfflineSyncBatch::STATUS_PROCESSING,
            'submitted_import_count'   => 1,
            'sync_started_at'          => now(),
        ]);

        $import = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '0008',
            'payload_hash'             => 'hash',
            'raw_payload'              => [
                'items' => [
                    ['product_id' => $this->exemptProduct->id, 'quantity' => 3, 'unit_price' => 50.00]
                ],
                'client_subtotal'  => 150.00,
                'client_tax_total' => 0.00,
                'client_total'     => 150.00,
            ],
            'status'                   => OfflineSalesImport::STATUS_PENDING,
            'submitted_at'             => now(),
        ]);

        $service = app(OfflineImportRecalculationService::class);
        $result = $service->recalculate($import);

        $this->assertEquals('150.0000', $result['server_total']);
        $this->assertEquals('0.0000', $result['server_tax_total']);
        
        $item = $result['server_recalculation']['items'][0];
        $this->assertEquals('0.0000', $item['tax_amount']);
        $this->assertEquals('150.0000', $item['tax_snapshot']['net_amount']);
        $this->assertEquals('150.0000', $item['tax_snapshot']['vat_exempt_amount']);
        $this->assertEquals('0.0000', $item['tax_snapshot']['vatable_amount']);
    }

    // =========================================================================
    // TC-28.8-09: No Sale row is created and reconciled_sale_id remains null.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_09_no_sale_row_created(): void
    {
        $this->postSync([
            'batch_reference' => 'BATCH-009',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0009',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => $this->vatableProduct->id,
                            'quantity'   => 1,
                            'unit_price' => 112.00,
                        ],
                    ],
                    'client_subtotal'  => 112.00,
                    'client_tax_total' => 12.00,
                    'client_total'     => 112.00,
                ],
            ],
        ]);

        $import = OfflineSalesImport::where('offline_sequence_number', self::PREFIX . '0009')->first();
        
        $this->assertNull($import->reconciled_sale_id);
        $this->assertNull($import->reconciled_at);
        
        $this->assertEquals(0, Sale::withoutGlobalScopes()->count());
    }

    // =========================================================================
    // TC-28.8-10: Missing client_total returns 422.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_10_missing_client_total_returns_422(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-010',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0010',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        ['product_id' => $this->vatableProduct->id, 'quantity' => 1, 'unit_price' => 112.00],
                    ],
                    'client_subtotal'  => 112.00,
                    'client_tax_total' => 12.00,
                    // client_total omitted
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['imports.0.client_total']);
    }

    // =========================================================================
    // TC-28.8-11: Idempotent replay still returns 200 after server verification.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_11_idempotent_replay_returns_200(): void
    {
        $payload = [
            'batch_reference' => 'BATCH-011',
            'imports'         => [
                [
                    'offline_sequence_number' => self::PREFIX . '0011',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        ['product_id' => $this->vatableProduct->id, 'quantity' => 1, 'unit_price' => 112.00],
                    ],
                    'client_subtotal'  => 112.00,
                    'client_tax_total' => 12.00,
                    'client_total'     => 112.00,
                ],
            ],
        ];

        $res1 = $this->postSync($payload);
        $res1->assertStatus(202);

        $res2 = $this->postSync($payload);
        $res2->assertStatus(200); // Replay
        
        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $res2->json('imports.0.status'));
        $this->assertEquals(1, OfflineSalesImport::where('offline_sequence_number', self::PREFIX . '0011')->count());
    }

    // =========================================================================
    // TC-28.8-12: Mixed batch produces server_verified, conflict, and rejected rows.
    // =========================================================================
    
    /** @test */
    public function test_tc_28_8_12_mixed_batch_produces_mixed_results(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-012',
            'imports'         => [
                [
                    // Valid match
                    'offline_sequence_number' => self::PREFIX . '00121',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [['product_id' => $this->vatableProduct->id, 'quantity' => 1, 'unit_price' => 112.00]],
                    'client_subtotal'  => 112.00,
                    'client_tax_total' => 12.00,
                    'client_total'     => 112.00,
                ],
                [
                    // Conflict
                    'offline_sequence_number' => self::PREFIX . '00122',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [['product_id' => $this->vatableProduct->id, 'quantity' => 1, 'unit_price' => 112.00]],
                    'client_subtotal'  => 150.00,
                    'client_tax_total' => 12.00,
                    'client_total'     => 150.00,
                ],
                [
                    // Rejected
                    'offline_sequence_number' => self::PREFIX . '00123',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [['product_id' => '00000000-0000-0000-0000-000000000999', 'quantity' => 1, 'unit_price' => 10.00]],
                    'client_subtotal'  => 10.00,
                    'client_tax_total' => 0.00,
                    'client_total'     => 10.00,
                ],
            ],
        ]);

        $response->assertStatus(202);
        $data = $response->json();

        $this->assertEquals(3, $data['submitted']);
        $this->assertEquals(2, $data['processed']); // Match + Conflict
        $this->assertEquals(1, $data['failed']);    // Rejected
        
        $statuses = collect($data['imports'])->pluck('status')->toArray();
        $this->assertContains(OfflineSalesImport::STATUS_SERVER_VERIFIED, $statuses);
        $this->assertContains(OfflineSalesImport::STATUS_CONFLICT, $statuses);
        $this->assertContains(OfflineSalesImport::STATUS_REJECTED, $statuses);
    }
}
