<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\OfflineTerminalJournal;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 28.7: Epic 28 Phase 2 Slice C — Offline Sync Validation & Reconciliation Service
 *
 * Test Matrix:
 *   TC-28.7-01  Valid batch with 3 valid imports returns 202
 *   TC-28.7-02  Same batch_reference replay returns 200, no duplicates
 *   TC-28.7-03  Terminal disabled returns 422 OFFLINE_NOT_ENABLED
 *   TC-28.7-04  Wrong prefix import is rejected but batch continues
 *   TC-28.7-05  Duplicate payload saved as duplicate
 *   TC-28.7-06  Import missing items is rejected
 *   TC-28.7-07  Import with unit_price = 0 is rejected
 *   TC-28.7-08  Late sync import flagged but remains pending
 *   TC-28.7-09  reconcileImport still throws BadMethodCallException
 *   TC-28.7-10  finalizeJournal still throws BadMethodCallException
 *   TC-28.7-11  Cashier with create_sale permission can sync
 *   TC-28.7-12  No Sale created; all reconciled_sale_id remain null
 *   TC-28.7-13  Missing batch_reference returns 422 validation error
 *   TC-28.7-14  Empty imports array returns 422 validation error
 */
class OfflineSyncValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $owner;
    protected SalesMachineProfile $profile;
    protected \App\Models\Product $product;

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

        $this->owner = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

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
            'offline_sales_enabled'                => null, // inherits from branch
            'offline_sequence_prefix'              => self::PREFIX,
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
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
            'selling_price'       => 150.00,
            'status'              => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Shared payload helpers
    // -------------------------------------------------------------------------

    private function validImport(string $seqSuffix = '0001', array $overrides = []): array
    {
        return array_merge([
            'offline_sequence_number' => self::PREFIX . $seqSuffix,
            'submitted_at'            => now()->toISOString(),
            'items'                   => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 2,
                    'unit_price' => 150.00,
                ],
            ],
            'client_subtotal'  => 300.00,
            'client_tax_total' => 0.00,
            'client_total'     => 300.00,
        ], $overrides);
    }

    private function postSync(array $payload, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $user ??= $this->cashier;

        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/offline-sync', $payload);
    }

    private function createActivePromotion(array $rewardOverrides = []): Promotion
    {
        $promotion = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Offline Promo',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $promotion->branches()->attach($this->branch->id);

        PromotionRule::create([
            'promotion_id' => $promotion->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'amount_off',
            'conditions' => ['min_spend_centavos' => 10000],
            'rewards' => array_merge(['amount_centavos' => 300], $rewardOverrides),
            'stackable' => false,
        ]);

        return $promotion;
    }

    // =========================================================================
    // TC-28.7-01: Valid batch with 3 valid imports returns 202
    // =========================================================================

    /** @test */
    public function test_tc_28_7_01_valid_batch_with_three_imports_returns_202(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-001',
            'imports'         => [
                $this->validImport('0001'),
                $this->validImport('0002'),
                $this->validImport('0003'),
            ],
        ]);

        $response->assertStatus(202);

        $data = $response->json();

        $this->assertEquals('BATCH-001', $data['batch_reference']);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals(3, $data['submitted']);
        $this->assertEquals(3, $data['processed']);
        $this->assertEquals(0, $data['failed']);
        $this->assertCount(3, $data['imports']);

        foreach ($data['imports'] as $import) {
            $this->assertEquals('server_verified', $import['status']);
        }
    }

    /** @test */
    public function test_tc_28_7_01_batch_and_import_records_are_persisted(): void
    {
        $this->postSync([
            'batch_reference' => 'BATCH-ABC',
            'imports'         => [$this->validImport('0001')],
        ]);

        $batch = OfflineSyncBatch::withoutGlobalScopes()
            ->where('batch_reference', 'BATCH-ABC')
            ->first();

        $this->assertNotNull($batch);
        $this->assertEquals('completed', $batch->status);

        $import = OfflineSalesImport::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->first();

        $this->assertNotNull($import);
        $this->assertEquals('server_verified', $import->status);
    }

    // =========================================================================
    // TC-28.7-02: Same batch_reference replay returns 200, no duplicates
    // =========================================================================

    /** @test */
    public function test_tc_28_7_02_replay_same_batch_reference_returns_200(): void
    {
        $payload = [
            'batch_reference' => 'BATCH-REPLAY',
            'imports'         => [$this->validImport('0001')],
        ];

        $first  = $this->postSync($payload);
        $second = $this->postSync($payload);

        $first->assertStatus(202);
        $second->assertStatus(200);

        // Same batch_id both times
        $this->assertEquals($first->json('batch_id'), $second->json('batch_id'));
    }

    /** @test */
    public function test_tc_28_7_02_replay_does_not_create_duplicate_batch(): void
    {
        $payload = [
            'batch_reference' => 'BATCH-NODUPE',
            'imports'         => [$this->validImport('0001')],
        ];

        $this->postSync($payload);
        $this->postSync($payload);

        $count = OfflineSyncBatch::withoutGlobalScopes()
            ->where('batch_reference', 'BATCH-NODUPE')
            ->where('tenant_id', $this->tenant->id)
            ->where('sales_machine_profile_id', $this->profile->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    /** @test */
    public function test_stale_promotion_hash_without_preview_is_accepted_with_warning(): void
    {
        $staleHash = app(CacheBootstrapService::class)
            ->calculateDiscountRulesVersionHash($this->tenant->id, $this->branch->id);

        $this->createActivePromotion(['amount_centavos' => 500]);

        $response = $this->postSync([
            'batch_reference' => 'BATCH-PROMO-WARN',
            'imports' => [
                $this->validImport('0001', [
                    'discount_rules_version_hash' => $staleHash,
                ]),
            ],
        ]);

        $response->assertStatus(202);
        $this->assertSame('accepted_with_warning', $response->json('imports.0.status'));

        $import = OfflineSalesImport::withoutGlobalScopes()
            ->where('offline_sequence_number', self::PREFIX . '0001')
            ->firstOrFail();

        $this->assertSame(OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING, $import->status);
        $this->assertStringContainsString('PROMOTION_RULE_HASH_STALE_NO_PREVIEW', $import->rejection_reason);
    }

    /** @test */
    public function test_stale_promotion_hash_with_preview_is_classified_as_conflict(): void
    {
        $staleHash = app(CacheBootstrapService::class)
            ->calculateDiscountRulesVersionHash($this->tenant->id, $this->branch->id);

        $promotion = $this->createActivePromotion(['amount_centavos' => 500]);

        $response = $this->postSync([
            'batch_reference' => 'BATCH-PROMO-CONFLICT',
            'imports' => [
                $this->validImport('0001', [
                    'discount_rules_version_hash' => $staleHash,
                    'promotion_discount_centavos' => 500,
                    'promotion_preview' => [
                        [
                            'promotion_id' => $promotion->id,
                            'discount_amount_centavos' => 500,
                        ],
                    ],
                ]),
            ],
        ]);

        $response->assertStatus(202);
        $this->assertSame('conflict', $response->json('imports.0.status'));

        $import = OfflineSalesImport::withoutGlobalScopes()
            ->where('offline_sequence_number', self::PREFIX . '0001')
            ->firstOrFail();

        $this->assertSame(OfflineSalesImport::STATUS_CONFLICT, $import->status);
        $this->assertStringContainsString('PROMOTION_RULE_HASH_STALE_WITH_PREVIEW', $import->conflict_notes);
    }

    // =========================================================================
    // TC-28.7-03: Terminal disabled returns 422 OFFLINE_NOT_ENABLED
    // =========================================================================

    /** @test */
    public function test_tc_28_7_03_tenant_disabled_returns_422(): void
    {
        $this->tenant->update(['offline_sales_enabled' => false]);

        $response = $this->postSync([
            'batch_reference' => 'BATCH-BLOCKED',
            'imports'         => [$this->validImport('0001')],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'OFFLINE_NOT_ENABLED']);

        $this->assertEquals(
            0,
            OfflineSyncBatch::withoutGlobalScopes()
                ->where('batch_reference', 'BATCH-BLOCKED')
                ->count()
        );
    }

    /** @test */
    public function test_tc_28_7_03_terminal_explicitly_disabled_returns_422(): void
    {
        $this->profile->update(['offline_sales_enabled' => false]);

        $response = $this->postSync([
            'batch_reference' => 'BATCH-TERM-DISABLED',
            'imports'         => [$this->validImport('0001')],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'OFFLINE_NOT_ENABLED']);
    }

    // =========================================================================
    // TC-28.7-04: Wrong prefix import is rejected but batch continues
    // =========================================================================

    /** @test */
    public function test_tc_28_7_04_wrong_prefix_import_is_rejected_batch_continues(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-PREFIX',
            'imports'         => [
                $this->validImport('0001'),                                          // correct
                $this->validImport('0002', ['offline_sequence_number' => 'WRONG-PREFIX-0001']), // wrong
                $this->validImport('0003'),                                          // correct
            ],
        ]);

        $response->assertStatus(202);

        $data = $response->json();
        $this->assertEquals(2, $data['processed']);
        $this->assertEquals(1, $data['failed']);

        $statuses = collect($data['imports'])->pluck('status')->toArray();
        $this->assertContains('server_verified', $statuses);
        $this->assertContains('rejected', $statuses);
    }

    // =========================================================================
    // TC-28.7-05: Duplicate payload saved as duplicate
    // =========================================================================

    /** @test */
    public function test_tc_28_7_05_duplicate_payload_is_saved_as_duplicate(): void
    {
        $sameImport = $this->validImport('0001');

        // First batch — import is pending
        $this->postSync([
            'batch_reference' => 'BATCH-DUP-A',
            'imports'         => [$sameImport],
        ]);

        // Second batch — same payload, different batch_reference
        $response = $this->postSync([
            'batch_reference' => 'BATCH-DUP-B',
            'imports'         => [$sameImport],
        ]);

        $response->assertStatus(202);

        $imports = collect($response->json('imports'));
        $this->assertEquals('duplicate', $imports->first()['status']);

        // Both rows must exist
        $allImports = OfflineSalesImport::withoutGlobalScopes()
            ->where('offline_sequence_number', self::PREFIX . '0001')
            ->where('tenant_id', $this->tenant->id)
            ->get();

        $this->assertCount(2, $allImports);
        $this->assertContains('server_verified', $allImports->pluck('status')->toArray());
        $this->assertContains('duplicate', $allImports->pluck('status')->toArray());
    }

    // =========================================================================
    // TC-28.7-06: Import missing items is rejected
    // =========================================================================

    /** @test */
    public function test_tc_28_7_06_import_missing_items_is_rejected(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-NO-ITEMS',
            'imports'         => [
                $this->validImport('0001', ['items' => []]), // empty items — fails form validation
            ],
        ]);

        // Empty items fails form request validation (min:1) → 422
        $response->assertStatus(422);
    }

    /** @test */
    public function test_tc_28_7_06_import_without_items_key_is_rejected_by_service(): void
    {
        // Bypass the form request by going directly to the service
        $service = app(OfflineReconciliationService::class);

        $import = new OfflineSalesImport([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'raw_payload'              => [
                'offline_sequence_number' => self::PREFIX . '0001',
                'submitted_at'            => now()->toISOString(),
                // 'items' key intentionally missing
            ],
            'status'      => OfflineSalesImport::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        $result = $service->validateImport($import, $this->profile);

        $this->assertFalse($result);
    }

    // =========================================================================
    // TC-28.7-07: Import with unit_price = 0 is rejected
    // =========================================================================

    /** @test */
    public function test_tc_28_7_07_unit_price_zero_fails_request_validation(): void
    {
        $import = $this->validImport('0001');
        $import['items'][0]['unit_price'] = 0;

        $response = $this->postSync([
            'batch_reference' => 'BATCH-ZERO-PRICE',
            'imports'         => [$import],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['imports.0.items.0.unit_price']);
    }

    /** @test */
    public function test_tc_28_7_07_unit_price_zero_is_rejected_by_service(): void
    {
        $service = app(OfflineReconciliationService::class);

        $import = new OfflineSalesImport([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'raw_payload'              => [
                'offline_sequence_number' => self::PREFIX . '0001',
                'submitted_at'            => now()->toISOString(),
                'items'                   => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 0],
                ],
            ],
            'status'       => OfflineSalesImport::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        $result = $service->validateImport($import, $this->profile);

        $this->assertFalse($result);
    }

    // =========================================================================
    // TC-28.7-08: Late sync import flagged but remains pending
    // =========================================================================

    /** @test */
    public function test_tc_28_7_08_late_sync_import_remains_pending_with_note(): void
    {
        $oldDate = now()->subHours(100)->toISOString(); // > 72h threshold

        $response = $this->postSync([
            'batch_reference' => 'BATCH-LATE',
            'imports'         => [
                $this->validImport('0001', ['submitted_at' => $oldDate]),
            ],
        ]);

        $response->assertStatus(202);

        $import = OfflineSalesImport::withoutGlobalScopes()
            ->where('offline_sequence_number', self::PREFIX . '0001')
            ->first();

        $this->assertNotNull($import);
        $this->assertEquals('server_verified', $import->status); // not rejected
        $this->assertStringContainsString('late_sync', $import->rejection_reason);
    }

    // =========================================================================
    // TC-28.7-09: reconcileImport still throws BadMethodCallException
    // =========================================================================

    /** @test */
    public function test_tc_28_7_09_reconcile_import_still_throws(): void
    {
        $service = app(OfflineReconciliationService::class);
        $import  = new OfflineSalesImport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reconcile an unsaved import.');

        $service->reconcileImport($import);
    }

    // =========================================================================
    // TC-28.7-10: finalizeJournal still throws BadMethodCallException
    // =========================================================================

    /** @test */
    public function test_tc_28_7_10_finalize_journal_still_throws(): void
    {
        $service = app(OfflineReconciliationService::class);
        $journal = new OfflineTerminalJournal();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not yet implemented.');

        $service->finalizeJournal($journal);
    }

    // =========================================================================
    // TC-28.7-11: Cashier with create_sale permission can sync
    // =========================================================================

    /** @test */
    public function test_tc_28_7_11_cashier_with_create_sale_can_submit_batch(): void
    {
        $this->assertTrue($this->cashier->hasPermission('create_sale'));

        $response = $this->postSync([
            'batch_reference' => 'BATCH-CASHIER',
            'imports'         => [$this->validImport('0001')],
        ]);

        $response->assertStatus(202);
    }

    // =========================================================================
    // TC-28.7-12: No Sale created; all reconciled_sale_id remain null
    // =========================================================================

    /** @test */
    public function test_tc_28_7_12_no_sale_is_created_reconciled_sale_id_is_null(): void
    {
        $this->postSync([
            'batch_reference' => 'BATCH-NOSALE',
            'imports'         => [
                $this->validImport('0001'),
                $this->validImport('0002'),
            ],
        ]);

        $imports = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->get();

        $this->assertNotEmpty($imports);

        foreach ($imports as $import) {
            $this->assertNull($import->reconciled_sale_id);
            $this->assertNull($import->reconciled_at);
        }

        // Confirm no Sale rows were created at all
        $saleCount = \App\Models\Sale::withoutGlobalScopes()->count();
        $this->assertEquals(0, $saleCount);
    }

    // =========================================================================
    // TC-28.7-13: Missing batch_reference returns 422 validation error
    // =========================================================================

    /** @test */
    public function test_tc_28_7_13_missing_batch_reference_returns_422(): void
    {
        $response = $this->postSync([
            // batch_reference intentionally omitted
            'imports' => [$this->validImport('0001')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['batch_reference']);
    }

    // =========================================================================
    // TC-28.7-14: Empty imports array returns 422 validation error
    // =========================================================================

    /** @test */
    public function test_tc_28_7_14_empty_imports_returns_422(): void
    {
        $response = $this->postSync([
            'batch_reference' => 'BATCH-EMPTY',
            'imports'         => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['imports']);
    }
}
