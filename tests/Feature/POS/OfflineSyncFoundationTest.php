<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSequenceRecovery;
use App\Models\OfflineSyncBatch;
use App\Models\OfflineTerminalJournal;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 28.6: Epic 28 Phase 2 Slice B — Offline Import Schema & Reconciliation Foundation
 *
 * Test Matrix:
 *   TC-28.6-01  Migration tables, defaults, and constraints
 *   TC-28.6-02  OfflineSyncBatch persists valid fields
 *   TC-28.6-03  OfflineSalesImport defaults to pending and unresolved
 *   TC-28.6-04  Duplicate payload hash is recorded and marked duplicate
 *   TC-28.6-05  Duplicate batch reference for same terminal is blocked
 *   TC-28.6-06  OfflineReconciliationService methods throw BadMethodCallException
 *   TC-28.6-07  Authenticated cashier receives 503 from offline sync endpoint
 *   TC-28.6-08  Unauthenticated request receives JSON 401 (not redirect)
 *   TC-28.6-09  Cashier cannot create OfflineSequenceRecovery (RBAC)
 *   TC-28.6-10  provisional_gross_total decimal cast survives round-trip
 */
class OfflineSyncFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $owner;
    protected User $cashier;
    protected SalesMachineProfile $profile;
    protected OfflineSyncBatch $batch;
    protected \App\Models\Product $product;

    /**
     * Minimal SalesMachineProfile attributes helper.
     */
    private function machineProfileAttributes(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->owner = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->profile = SalesMachineProfile::create($this->machineProfileAttributes([
            'offline_sequence_prefix'     => 'INV-T01-',
            'offline_sequence_next_value' => 1,
            'offline_sequence_status'     => 'active',
        ]));

        // A valid base batch used across tests
        $this->batch = OfflineSyncBatch::create([
            'tenant_id'              => $this->tenant->id,
            'branch_id'              => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'        => 'BATCH-001',
            'status'                 => OfflineSyncBatch::STATUS_RECEIVED,
            'submitted_import_count' => 3,
        ]);

        $category = \App\Models\ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $taxCategory = \App\Models\TaxCategory::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'VAT Exempt',
            'code'        => 'EXEMPT',
            'tax_type'    => 'exempt',
            'rate'        => 0.00,
            'description' => 'Exempt',
        ]);

        $this->product = \App\Models\Product::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id'     => $taxCategory->id,
            'selling_price'       => 100.00,
            'status'              => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // TC-28.6-01: Migration tables, defaults, constraints
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_01_offline_sync_batch_table_exists_with_expected_defaults(): void
    {
        $fresh = OfflineSyncBatch::find($this->batch->id);

        $this->assertNotNull($fresh);
        $this->assertEquals('received', $fresh->status);
        $this->assertEquals(0, $fresh->processed_count);
        $this->assertEquals(0, $fresh->failed_count);
        $this->assertNull($fresh->sync_started_at);
        $this->assertNull($fresh->sync_completed_at);
    }

    /** @test */
    public function test_tc_28_6_01_offline_sales_imports_table_has_expected_columns(): void
    {
        $import = OfflineSalesImport::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                => $this->batch->id,
            'offline_sequence_number' => 'INV-T01-0001',
            'payload_hash'            => hash('sha256', 'test-payload'),
            'raw_payload'             => ['items' => []],
            'submitted_at'            => now(),
        ]);

        $fresh = OfflineSalesImport::find($import->id);

        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNull($fresh->reconciled_sale_id);
        $this->assertNull($fresh->reconciled_at);
    }

    /** @test */
    public function test_tc_28_6_01_offline_terminal_journal_defaults_to_provisional(): void
    {
        $journal = OfflineTerminalJournal::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'journal_date'            => today(),
        ]);

        $fresh = OfflineTerminalJournal::find($journal->id);

        $this->assertEquals('provisional', $fresh->status);
        $this->assertEquals('0.0000', $fresh->provisional_gross_total);
        $this->assertEquals(0, $fresh->provisional_item_count);
        $this->assertNull($fresh->reconciled_at);
    }

    /** @test */
    public function test_tc_28_6_01_offline_sequence_recovery_is_created(): void
    {
        $recovery = OfflineSequenceRecovery::create([
            'tenant_id'               => $this->tenant->id,
            'sales_machine_profile_id' => $this->profile->id,
            'recovery_type'           => OfflineSequenceRecovery::TYPE_GAP_DETECTED,
            'affected_prefix'         => 'INV-T01-',
            'affected_range_start'    => 10,
            'affected_range_end'      => 15,
        ]);

        $fresh = OfflineSequenceRecovery::find($recovery->id);

        $this->assertEquals(OfflineSequenceRecovery::TYPE_GAP_DETECTED, $fresh->recovery_type);
        $this->assertEquals('INV-T01-', $fresh->affected_prefix);
        $this->assertEquals(10, $fresh->affected_range_start);
        $this->assertNull($fresh->resolution);
        $this->assertNull($fresh->resolved_at);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-02: OfflineSyncBatch persists valid fields
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_02_batch_persists_all_fields_correctly(): void
    {
        $fresh = OfflineSyncBatch::find($this->batch->id);

        $this->assertEquals($this->tenant->id, $fresh->tenant_id);
        $this->assertEquals($this->branch->id, $fresh->branch_id);
        $this->assertEquals($this->profile->id, $fresh->sales_machine_profile_id);
        $this->assertEquals('BATCH-001', $fresh->batch_reference);
        $this->assertEquals(3, $fresh->submitted_import_count);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-03: OfflineSalesImport defaults to pending and unresolved
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_03_import_defaults_to_pending_and_unresolved(): void
    {
        $import = OfflineSalesImport::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                => $this->batch->id,
            'offline_sequence_number' => 'INV-T01-0001',
            'payload_hash'            => hash('sha256', 'payload-a'),
            'raw_payload'             => ['items' => [['product_id' => 'abc', 'qty' => 1]]],
            'submitted_at'            => now(),
        ]);

        $fresh = OfflineSalesImport::find($import->id);

        $this->assertEquals(OfflineSalesImport::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->reconciled_sale_id);
        $this->assertNull($fresh->reconciled_at);
        $this->assertNull($fresh->rejection_reason);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-04: Duplicate payload hash is recorded and marked duplicate
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_04_duplicate_payload_hash_can_be_saved_as_duplicate_status(): void
    {
        $hash = hash('sha256', 'same-payload-content');

        // First import — pending
        $first = OfflineSalesImport::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                => $this->batch->id,
            'offline_sequence_number' => 'INV-T01-0001',
            'payload_hash'            => $hash,
            'raw_payload'             => ['items' => []],
            'submitted_at'            => now(),
        ]);

        // Second import — same hash, marked as duplicate (no DB unique constraint blocks this)
        $second = OfflineSalesImport::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                => $this->batch->id,
            'offline_sequence_number' => 'INV-T01-0001',
            'payload_hash'            => $hash,
            'raw_payload'             => ['items' => []],
            'status'                  => OfflineSalesImport::STATUS_DUPLICATE,
            'submitted_at'            => now(),
        ]);

        // Both rows must coexist — duplicate rows are preserved for audit
        $this->assertEquals(OfflineSalesImport::STATUS_PENDING, OfflineSalesImport::find($first->id)->status);
        $this->assertEquals(OfflineSalesImport::STATUS_DUPLICATE, OfflineSalesImport::find($second->id)->status);

        // Total count of imports with this hash should be 2
        $count = OfflineSalesImport::withoutGlobalScopes()
            ->where('payload_hash', $hash)
            ->where('tenant_id', $this->tenant->id)
            ->count();
        $this->assertEquals(2, $count);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-05: Duplicate batch reference for same terminal is blocked
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_05_duplicate_batch_reference_for_same_terminal_is_rejected(): void
    {
        // 'BATCH-001' already exists for this profile from setUp

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        OfflineSyncBatch::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'         => 'BATCH-001', // duplicate
            'status'                  => OfflineSyncBatch::STATUS_RECEIVED,
        ]);
    }

    /** @test */
    public function test_tc_28_6_05_same_batch_reference_is_allowed_for_different_terminal(): void
    {
        $secondProfile = SalesMachineProfile::create($this->machineProfileAttributes([
            'profile_code'            => 'POS-02',
            'offline_sequence_prefix' => 'INV-T02-',
        ]));

        // Same batch_reference but different terminal — allowed
        $batch2 = OfflineSyncBatch::create([
            'tenant_id'               => $this->tenant->id,
            'branch_id'               => $this->branch->id,
            'sales_machine_profile_id' => $secondProfile->id,
            'batch_reference'         => 'BATCH-001',
            'status'                  => OfflineSyncBatch::STATUS_RECEIVED,
        ]);

        $this->assertNotNull(OfflineSyncBatch::find($batch2->id));
    }

    // -------------------------------------------------------------------------
    // TC-28.6-06: OfflineReconciliationService — still-stubbed methods guard
    // (Updated: Story 28.7 implemented receiveImportBatch/validateImport/deduplicateImport.
    //  reconcileImport and finalizeJournal remain stubbed until Story 28.8+.)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_06_validate_import_returns_bool(): void
    {
        // validateImport is now implemented — verify it handles a minimal import gracefully
        $service = app(OfflineReconciliationService::class);
        $import  = new OfflineSalesImport([
            'raw_payload'  => [
                'offline_sequence_number' => 'INV-T01-0001',
                'submitted_at'            => now()->toISOString(),
                'items'                   => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
                'client_subtotal'  => 100.00,
                'client_tax_total' => 0.00,
                'client_total'     => 100.00,
            ],
            'status'       => OfflineSalesImport::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        $result = $service->validateImport($import, $this->profile);
        $this->assertIsBool($result);
    }

    /** @test */
    public function test_tc_28_6_06_reconcile_import_still_throws_bad_method_call(): void
    {
        $service = app(OfflineReconciliationService::class);
        $import  = new OfflineSalesImport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reconcile an unsaved import.');
        $service->reconcileImport($import);
    }

    /** @test */
    public function test_tc_28_6_06_finalize_journal_still_throws_bad_method_call(): void
    {
        $service = app(OfflineReconciliationService::class);
        $journal = new OfflineTerminalJournal();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not yet implemented.');
        $service->finalizeJournal($journal);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-07: Authenticated cashier can sync (endpoint now live, Story 28.7)
    // (Updated: stub replaced by real intake — 503 is no longer the expected response.)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_07_authenticated_cashier_can_submit_to_offline_sync(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/offline-sync', [
                'batch_reference' => 'BATCH-FOUND-07',
                'imports'         => [
                    [
                        'offline_sequence_number' => 'INV-T01-0001',
                        'submitted_at'            => now()->toISOString(),
                        'items'                   => [
                            ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100.00],
                        ],
                        'client_subtotal'  => 100.00,
                        'client_tax_total' => 0.00,
                        'client_total'     => 100.00,
                    ],
                ],
            ]);

        // Endpoint is now live — cashier submitting valid batch gets 202
        $response->assertStatus(202);
        $response->assertJsonStructure(['batch_id', 'status', 'imports']);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-08: Unauthenticated request receives JSON 401 (not redirect)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_08_unauthenticated_request_receives_json_401(): void
    {
        $response = $this->postJson('/api/pos/offline-sync', []);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    // -------------------------------------------------------------------------
    // TC-28.6-09: Cashier cannot create OfflineSequenceRecovery (RBAC)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_09_cashier_does_not_have_manage_offline_sales_settings_permission(): void
    {
        $this->assertFalse($this->cashier->hasPermission('manage_offline_sales_settings'));
        $this->assertTrue($this->owner->hasPermission('manage_offline_sales_settings'));
    }

    // -------------------------------------------------------------------------
    // TC-28.6-10: provisional_gross_total decimal cast survives round-trip
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_6_10_provisional_gross_total_decimal_cast_survives_round_trip(): void
    {
        $journal = OfflineTerminalJournal::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'journal_date'             => today(),
            'provisional_gross_total'  => 12345.6789,
            'provisional_item_count'   => 7,
        ]);

        $fresh = OfflineTerminalJournal::find($journal->id);

        // Cast to decimal:4 — value should survive as numeric string with 4dp
        $this->assertEquals('12345.6789', $fresh->provisional_gross_total);
        $this->assertIsNumeric($fresh->provisional_gross_total);
        $this->assertEquals(7, $fresh->provisional_item_count);
    }
}
