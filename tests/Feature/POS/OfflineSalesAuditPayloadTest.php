<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineSalesAuditPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected SalesMachineProfile $profile;
    protected \App\Models\Product $product;

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
            'tenant_id'                   => $this->tenant->id,
            'branch_id'                   => $this->branch->id,
            'profile_code'                => 'T03',
            'offline_sequence_prefix'     => 'OFF-T03-20260705-',
            'offline_sequence_next_value' => 1,
            'status'                      => 'active',
        ]);

        $category = \App\Models\ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'General Category',
            'code'      => 'GEN',
            'status'    => 'active',
        ]);

        $taxCategory = \App\Models\TaxCategory::create([
            'tenant_id'   => $this->tenant->id,
            'code'        => 'EXEMPT',
            'name'        => 'Exempt Tax',
            'tax_type'    => 'exempt',
            'rate'        => 0.00,
            'description' => 'Exempt',
        ]);

        $this->product = \App\Models\Product::create([
            'tenant_id'           => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id'     => $taxCategory->id,
            'name'                => 'Mock Product',
            'sku'                 => 'MOCK-1',
            'selling_price'       => 150.00,
            'status'              => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_audit_payload_reconciliation_verifies_all_25_fields()
    {
        $bootstrapService = app(CacheBootstrapService::class);
        $snapshot = $bootstrapService->generatePayload($this->tenant, $this->branch, $this->cashier);

        $payload = [
            'tenant_id'                       => $this->tenant->id,
            'branch_id'                       => $this->branch->id,
            'terminal_id'                     => $this->profile->id,
            'device_id'                       => 'DEV-MOCK-UUID',
            'cashier_shift_id'                => null,
            'timecard_id'                     => null,
            'local_transaction_reference'     => 'OFF-T03-20260705-000001',
            'local_receipt_number'            => 'OFF-T03-20260705-000001',
            'business_date'                   => '2026-07-05',
            'terminal_timestamp'              => now()->toISOString(),
            'timezone'                        => 'UTC',
            'sales_machine_profile_id'        => $this->profile->id,
            'config_snapshot_hash'            => $snapshot['config_snapshot_hash'],
            'layout_version_hash'             => $snapshot['layout_version_hash'],
            'catalog_version_hash'            => $snapshot['catalog_version_hash'],
            'tax_configuration_version_hash'  => $snapshot['tax_configuration_version_hash'],
            'discount_rules_version_hash'     => $snapshot['discount_rules_version_hash'],
            'payment_methods_version_hash'    => $snapshot['payment_methods_version_hash'],
            'terminal_policy_version_hash'    => $snapshot['terminal_policy_version_hash'],
            'printer_profile_version_hash'    => $snapshot['printer_profile_version_hash'],
            'config_snapshot'                 => $snapshot['config_snapshot'],
            'cart_snapshot'                   => ['items' => []],
            'payment_method'                  => 'cash',
            'gross_amount_centavos'           => 30000,
            'discount_total_centavos'         => 0,
            'taxable_amount_centavos'         => 30000,
            'tax_amount_centavos'             => 0,
            'net_amount_centavos'             => 30000,
            'sync_status'                     => 'pending',
            'sync_attempt_count'              => 0,
            'last_sync_attempt_at'            => null,
        ];

        // Compute hashes
        $payloadHash = hash('sha256', $this->canonicalJson($payload));
        $rowHash = hash('sha256', $payloadHash . 'OFF-T03-20260705-000001' . 'BATCH-MOCK');

        $importPayload = array_merge($payload, [
            'offline_sequence_number'         => 'OFF-T03-20260705-000001',
            'submitted_at'                    => now()->toISOString(),
            'items'                           => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 2,
                    'unit_price' => 150.00,
                ]
            ],
            'client_subtotal'                 => 300.00,
            'client_tax_total'                => 0.00,
            'client_total'                    => 300.00,
            'payload_hash'                    => $payloadHash,
            'row_hash'                        => $rowHash,
            'previous_hash'                   => null,
        ]);

        $batchPayload = [
            'batch_reference' => 'BATCH-MOCK',
            'imports'         => [$importPayload]
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/offline-sync', $batchPayload);

        $response->assertStatus(202);

        $import = OfflineSalesImport::where('offline_sequence_number', 'OFF-T03-20260705-000001')->firstOrFail();

        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $import->status);
        $this->assertEquals('DEV-MOCK-UUID', $import->raw_payload['device_id']);
        $this->assertEquals($snapshot['catalog_version_hash'], $import->raw_payload['catalog_version_hash']);
        $this->assertEquals($snapshot['config_snapshot_hash'], $import->raw_payload['config_snapshot_hash']);
        $this->assertEquals($snapshot['payment_methods_version_hash'], $import->raw_payload['payment_methods_version_hash']);
        $this->assertEquals($snapshot['config_snapshot_hash'], $import->raw_payload['config_snapshot']['config_snapshot_hash']);
        $this->assertEquals($rowHash, $import->raw_payload['row_hash']);
    }

    public function test_offline_sync_uses_requested_terminal_profile_when_branch_has_multiple_active_profiles()
    {
        $targetProfile = SalesMachineProfile::create([
            'tenant_id'                   => $this->tenant->id,
            'branch_id'                   => $this->branch->id,
            'profile_code'                => 'T09',
            'offline_sequence_prefix'     => 'OFF-T09-20260705-',
            'offline_sequence_next_value' => 1,
            'status'                      => 'active',
        ]);

        $bootstrapService = app(CacheBootstrapService::class);
        $taxHash = $bootstrapService->calculateTaxConfigHash($this->tenant->id, $this->branch->id);

        $payload = [
            'tenant_id'                       => $this->tenant->id,
            'branch_id'                       => $this->branch->id,
            'terminal_id'                     => $targetProfile->id,
            'device_id'                       => 'DEV-MULTI-TERMINAL',
            'cashier_shift_id'                => null,
            'timecard_id'                     => null,
            'local_transaction_reference'     => 'OFF-T09-20260705-000001',
            'local_receipt_number'            => 'OFF-T09-20260705-000001',
            'business_date'                   => '2026-07-05',
            'terminal_timestamp'              => now()->toISOString(),
            'timezone'                        => 'UTC',
            'sales_machine_profile_id'        => $targetProfile->id,
            'catalog_version_hash'            => null,
            'tax_configuration_version_hash'  => $taxHash,
            'cart_snapshot'                   => ['items' => []],
            'payment_method'                  => 'cash',
            'gross_amount_centavos'           => 15000,
            'discount_total_centavos'         => 0,
            'taxable_amount_centavos'         => 15000,
            'tax_amount_centavos'             => 0,
            'net_amount_centavos'             => 15000,
            'sync_status'                     => 'pending',
            'sync_attempt_count'              => 0,
            'last_sync_attempt_at'            => null,
        ];

        $payloadHash = hash('sha256', $this->canonicalJson($payload));
        $rowHash = hash('sha256', $payloadHash . 'OFF-T09-20260705-000001' . 'BATCH-MULTI-TERMINAL');

        $batchPayload = [
            'batch_reference' => 'BATCH-MULTI-TERMINAL',
            'imports'         => [
                array_merge($payload, [
                    'offline_sequence_number' => 'OFF-T09-20260705-000001',
                    'submitted_at'            => now()->toISOString(),
                    'items'                   => [
                        [
                            'product_id' => $this->product->id,
                            'quantity'   => 1,
                            'unit_price' => 150.00,
                        ],
                    ],
                    'client_subtotal'         => 150.00,
                    'client_tax_total'        => 0.00,
                    'client_total'            => 150.00,
                    'payload_hash'            => $payloadHash,
                    'row_hash'                => $rowHash,
                    'previous_hash'           => null,
                ]),
            ],
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $targetProfile->id)
            ->postJson('/api/pos/offline-sync', $batchPayload);

        $response->assertStatus(202);

        $import = OfflineSalesImport::where('offline_sequence_number', 'OFF-T09-20260705-000001')->firstOrFail();

        $this->assertEquals($targetProfile->id, $import->sales_machine_profile_id);
        $this->assertEquals(OfflineSalesImport::STATUS_SERVER_VERIFIED, $import->status);
    }

    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = json_decode($this->canonicalJson($value), true);
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
