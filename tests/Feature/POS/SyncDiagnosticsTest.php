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
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $owner;
    protected SalesMachineProfile $profile;
    protected Product $vatableProduct;

    private const PREFIX = 'REG01-';

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
            'offline_sales_enabled'                => true,
            'offline_sequence_prefix'              => self::PREFIX,
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
        ]);

        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $vatCategory = TaxCategory::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'VAT 12%',
            'code'        => 'VAT_12',
            'tax_type'    => 'vatable',
            'rate'        => 12.00,
            'description' => 'Standard VAT',
        ]);

        $this->vatableProduct = Product::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id'     => $vatCategory->id,
            'selling_price'       => 112.00, // Inclusive VAT
            'status'              => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    // =========================================================================
    // Sandbox Validation Routes Tests
    // =========================================================================

    /** @test */
    public function test_sandbox_validation_success_with_matching_recalculation(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/sandbox/validate', [
                'offline_sequence_number' => self::PREFIX . '1001',
                'submitted_at'            => now()->toIso8601String(),
                'items'                   => [
                    [
                        'product_id' => $this->vatableProduct->id,
                        'quantity'   => 1,
                        'unit_price' => 112.00,
                    ]
                ],
                'client_subtotal'  => 112.00,
                'client_tax_total' => 12.00,
                'client_total'     => 112.00,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'valid'          => true,
            'classification' => 'server_verified',
            'checks'         => [
                'schema'            => 'passed',
                'checksum'          => 'passed',
                'tax_recalculation' => 'passed',
                'sequence_format'   => 'passed',
            ],
            'computed_totals' => [
                'gross_amount' => '112.00',
                'net_amount'   => '100.00',
                'vat_amount'   => '12.00',
            ]
        ]);

        // Assert database is untouched
        $this->assertEquals(0, Sale::withoutGlobalScopes()->count());
        $this->assertEquals(0, OfflineSalesImport::withoutGlobalScopes()->count());
    }

    /** @test */
    public function test_sandbox_validation_returns_conflict_on_total_mismatch(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/sandbox/validate', [
                'offline_sequence_number' => self::PREFIX . '1002',
                'submitted_at'            => now()->toIso8601String(),
                'items'                   => [
                    [
                        'product_id' => $this->vatableProduct->id,
                        'quantity'   => 1,
                        'unit_price' => 112.00,
                    ]
                ],
                // Server expects 112 gross, 12 vat, 100 net. Client sends incorrect totals.
                'client_subtotal'  => 100.00,
                'client_tax_total' => 0.00,
                'client_total'     => 100.00,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'valid'          => false,
            'classification' => 'conflict',
            'checks'         => [
                'schema'            => 'passed',
                'checksum'          => 'passed',
                'tax_recalculation' => 'failed',
                'sequence_format'   => 'passed',
            ],
            'errors'         => [
                [
                    'code'  => 'TAX_RECALCULATION_MISMATCH',
                    'field' => 'client_total'
                ]
            ]
        ]);

        $this->assertEquals(0, OfflineSalesImport::withoutGlobalScopes()->count());
    }

    /** @test */
    public function test_sandbox_validation_returns_rejection_on_prefix_mismatch(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/sandbox/validate', [
                'offline_sequence_number' => 'WRONGPREFIX-001',
                'submitted_at'            => now()->toIso8601String(),
                'items'                   => [
                    [
                        'product_id' => $this->vatableProduct->id,
                        'quantity'   => 1,
                        'unit_price' => 112.00,
                    ]
                ],
                'client_subtotal'  => 112.00,
                'client_tax_total' => 12.00,
                'client_total'     => 112.00,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'valid'          => false,
            'classification' => 'rejected',
            'checks'         => [
                'schema'            => 'passed',
                'sequence_format'   => 'failed',
            ],
            'errors'         => [
                [
                    'code'  => 'INVALID_SEQUENCE_PREFIX',
                    'field' => 'offline_sequence_number'
                ]
            ]
        ]);
    }

    // =========================================================================
    // Submission Status Lookup Tests
    // =========================================================================

    /** @test */
    public function test_submission_lookup_by_uuid_successful(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-001',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
            'submitted_import_count'   => 1,
            'processed_count'          => 1,
            'failed_count'             => 0,
            'sync_started_at'          => now()->subMinutes(1),
            'sync_completed_at'        => now(),
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("/api/pos/submissions/{$batch->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'submission_uuid' => $batch->id,
            'status'          => 'posted',
            'summary'         => [
                'submitted_count' => 1,
                'accepted_count'  => 1,
                'rejected_count'  => 0,
            ]
        ]);
    }

    /** @test */
    public function test_submission_lookup_by_uuid_returns_404_cross_tenant_isolation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($otherTenant);

        // Switch to other tenant to seed their data safely
        app(TenantContext::class)->setTenant($otherTenant);

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status'    => 'active',
        ]);

        $otherProfile = SalesMachineProfile::create([
            'tenant_id'                            => $otherTenant->id,
            'branch_id'                            => $otherBranch->id,
            'profile_code'                         => 'OTHER-POS',
            'machine_identification_number'        => 'MIN-002',
            'machine_serial_number'                => 'SER-002',
            'status'                               => 'active',
        ]);

        $otherBatch = OfflineSyncBatch::create([
            'tenant_id'                => $otherTenant->id,
            'branch_id'                => $otherBranch->id,
            'sales_machine_profile_id' => $otherProfile->id,
            'batch_reference'          => 'REF-999',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
            'submitted_import_count'   => 1,
            'processed_count'          => 1,
        ]);

        // Restore active tenant context
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("/api/pos/submissions/{$otherBatch->id}");

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'SUBMISSION_NOT_FOUND',
        ]);
    }

    /** @test */
    public function test_submission_lookup_by_sequence_successful(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-002',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
            'submitted_import_count'   => 1,
        ]);

        $import = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '2001',
            'payload_hash'             => 'hash2001',
            'raw_payload'              => ['items' => []],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("/api/pos/submissions/sequence/" . self::PREFIX . "2001");

        $response->assertStatus(200);
        $response->assertJson([
            'offline_sequence_number' => self::PREFIX . '2001',
            'status'                  => 'posted',
        ]);
    }

    /** @test */
    public function test_submission_lookup_by_sequence_returns_404_on_prefix_mismatch(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("/api/pos/submissions/sequence/FOREIGNPREFIX-2001");

        $response->assertStatus(404);
    }

    // =========================================================================
    // Back Office Monitor Tests
    // =========================================================================

    /** @test */
    public function test_owner_can_access_monitor_dashboard_and_fetch_data(): void
    {
        $responseIndex = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->get('/admin/terminal-sync-monitor');

        $responseIndex->assertStatus(200);

        $responseData = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $responseData->assertStatus(200);
        $responseData->assertJsonStructure([
            'terminals',
            'recent_syncs'
        ]);
    }

    /** @test */
    public function test_cashier_cannot_access_monitor_endpoints(): void
    {
        $responseIndex = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->get('/admin/terminal-sync-monitor');

        $responseIndex->assertStatus(403); // RBAC Forbidden

        $responseData = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $responseData->assertStatus(403);
    }

    // =========================================================================
    // Config Snapshot & Drift Auditing Tests
    // =========================================================================

    /** @test */
    public function test_monitor_data_no_offline_import_exists(): void
    {
        // Delete all imports to be sure
        OfflineSalesImport::query()->delete();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertNotNull($terminal);
        $this->assertSame('no_sync_log', $terminal['config_audit']['config_status']);
        $this->assertFalse($terminal['config_audit']['has_config_drift']);
        $this->assertFalse($terminal['config_audit']['is_stale_report']);
    }

    /** @test */
    public function test_monitor_data_latest_payload_has_no_config_hashes(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-1',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9001',
            'payload_hash'             => 'hash9001',
            'raw_payload'              => ['some_random_field' => 'random_val'], // no config snapshot or hashes
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertSame('not_reported', $terminal['config_audit']['config_status']);
        $this->assertNull($terminal['config_audit']['has_config_drift']);
        $this->assertContains('catalog', $terminal['config_audit']['not_reported_components']);
        $this->assertContains('layout', $terminal['config_audit']['not_reported_components']);
    }

    /** @test */
    public function test_monitor_data_catalog_hash_mismatch(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        $clientSnapshot = $serverSnapshot;
        $clientSnapshot['catalog'] = 'mismatched-catalog-hash'; // force drift

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-2',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9002',
            'payload_hash'             => 'hash9002',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $clientSnapshot['layout'],
                    'catalog_version_hash' => $clientSnapshot['catalog'],
                    'tax_configuration_version_hash' => $clientSnapshot['tax'],
                    'discount_rules_version_hash' => $clientSnapshot['discounts'],
                    'payment_methods_version_hash' => $clientSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $clientSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $clientSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertSame('drifted', $terminal['config_audit']['config_status']);
        $this->assertTrue($terminal['config_audit']['has_config_drift']);
        $this->assertSame(['catalog'], $terminal['config_audit']['drifted_components']);

        // Check catalog component is labeled drifted
        $catalogComp = collect($terminal['config_audit']['components'])->firstWhere('key', 'catalog');
        $this->assertSame('drifted', $catalogComp['status']);
    }

    /** @test */
    public function test_monitor_data_tax_hash_mismatch(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        $clientSnapshot = $serverSnapshot;
        $clientSnapshot['tax'] = 'mismatched-tax-hash';

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-3',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9003',
            'payload_hash'             => 'hash9003',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $clientSnapshot['layout'],
                    'catalog_version_hash' => $clientSnapshot['catalog'],
                    'tax_configuration_version_hash' => $clientSnapshot['tax'],
                    'discount_rules_version_hash' => $clientSnapshot['discounts'],
                    'payment_methods_version_hash' => $clientSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $clientSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $clientSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertSame('drifted', $terminal['config_audit']['config_status']);
        $this->assertSame(['tax'], $terminal['config_audit']['drifted_components']);
    }

    /** @test */
    public function test_monitor_data_multiple_component_mismatch(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        $clientSnapshot = $serverSnapshot;
        $clientSnapshot['layout'] = 'mismatched-layout-hash';
        $clientSnapshot['catalog'] = 'mismatched-catalog-hash';

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-4',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9004',
            'payload_hash'             => 'hash9004',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $clientSnapshot['layout'],
                    'catalog_version_hash' => $clientSnapshot['catalog'],
                    'tax_configuration_version_hash' => $clientSnapshot['tax'],
                    'discount_rules_version_hash' => $clientSnapshot['discounts'],
                    'payment_methods_version_hash' => $clientSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $clientSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $clientSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertSame('drifted', $terminal['config_audit']['config_status']);
        $this->assertEqualsCanonicalizing(['layout', 'catalog'], $terminal['config_audit']['drifted_components']);
    }

    /** @test */
    public function test_monitor_data_missing_discount_hash_reported_as_not_reported(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-5',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9005',
            'payload_hash'             => 'hash9005',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $serverSnapshot['layout'],
                    'catalog_version_hash' => $serverSnapshot['catalog'],
                    'tax_configuration_version_hash' => $serverSnapshot['tax'],
                    'discount_rules_version_hash' => null, // missing discount hash
                    'payment_methods_version_hash' => $serverSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $serverSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $serverSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        // Should NOT be drifted, because discount was simply missing/not reported, and others match
        $this->assertSame('synced', $terminal['config_audit']['config_status']);
        $this->assertFalse($terminal['config_audit']['has_config_drift']);
        $this->assertSame(['discounts'], $terminal['config_audit']['not_reported_components']);
        $this->assertEmpty($terminal['config_audit']['drifted_components']);
    }

    /** @test */
    public function test_monitor_data_malformed_raw_payload(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-6',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9006',
            'payload_hash'             => 'hash9006',
            'raw_payload'              => [], // empty array / malformed
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertSame('invalid_payload', $terminal['config_audit']['config_status']);
        $this->assertNull($terminal['config_audit']['has_config_drift']);
    }

    /** @test */
    public function test_monitor_data_printer_placeholder(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-7',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9007',
            'payload_hash'             => 'hash9007',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $serverSnapshot['layout'],
                    'catalog_version_hash' => $serverSnapshot['catalog'],
                    'tax_configuration_version_hash' => $serverSnapshot['tax'],
                    'discount_rules_version_hash' => $serverSnapshot['discounts'],
                    'payment_methods_version_hash' => $serverSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $serverSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $serverSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $components = $terminal['config_audit']['components'];
        $printerComp = collect($components)->firstWhere('key', 'printer_profile');

        $this->assertNotNull($printerComp);
        $this->assertSame('placeholder', $printerComp['status']);
    }

    /** @test */
    public function test_monitor_data_old_but_matching_report(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-8',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9008',
            'payload_hash'             => 'hash9008',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $serverSnapshot['layout'],
                    'catalog_version_hash' => $serverSnapshot['catalog'],
                    'tax_configuration_version_hash' => $serverSnapshot['tax'],
                    'discount_rules_version_hash' => $serverSnapshot['discounts'],
                    'payment_methods_version_hash' => $serverSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $serverSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $serverSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now()->subHours(25), // older than 24h
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertSame('stale_report', $terminal['config_audit']['config_status']);
        $this->assertTrue($terminal['config_audit']['is_stale_report']);
    }

    /** @test */
    public function test_monitor_data_fallback_payload_format_works(): void
    {
        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-AUDIT-9',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        // Fallback format 1: top level catalog and tax configuration version hashes
        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9009',
            'payload_hash'             => 'hash9009',
            'raw_payload'              => [
                'catalog_version_hash' => 'top-level-catalog',
                'tax_configuration_version_hash' => 'top-level-tax',
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response1 = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $terminal1 = collect($response1->json('terminals'))->firstWhere('id', $this->profile->id);
        $this->assertSame('top-level-catalog', $terminal1['config_audit']['client_snapshot']['catalog']);
        $this->assertSame('top-level-tax', $terminal1['config_audit']['client_snapshot']['tax']);

        // Fallback format 2: under 'offline' key
        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => self::PREFIX . '9010',
            'payload_hash'             => 'hash9010',
            'raw_payload'              => [
                'offline' => [
                    'catalog_version_hash' => 'offline-catalog',
                    'tax_configuration_version_hash' => 'offline-tax',
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now()->addSecond(),
        ]);

        $response2 = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $terminal2 = collect($response2->json('terminals'))->firstWhere('id', $this->profile->id);
        $this->assertSame('offline-catalog', $terminal2['config_audit']['client_snapshot']['catalog']);
        $this->assertSame('offline-tax', $terminal2['config_audit']['client_snapshot']['tax']);
    }
}
