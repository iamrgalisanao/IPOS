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

class OfflineSyncStatusWorkflowTest extends TestCase
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

    public function test_config_drift_results_in_accepted_with_warning_status()
    {
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
            'catalog_version_hash'            => str_repeat('a', 64), // Config drift: catalog hash mismatched
            'tax_configuration_version_hash'  => str_repeat('b', 64), // Config drift: tax hash mismatched
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
        $this->assertEquals(OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING, $import->status);
        $this->assertStringContainsString('Config drift', $import->rejection_reason);
    }

    public function test_sequence_gap_or_out_of_order_blocks_batch()
    {
        // First create a parent batch record
        $prevBatch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-PREV1',
            'status'                   => 'completed',
            'submitted_import_count'  => 1,
            'sync_started_at'          => now(),
        ]);

        // First successful import in DB is sequence 1
        $import1 = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $prevBatch->id,
            'offline_sequence_number'  => 'OFF-T03-20260705-000001',
            'payload_hash'             => 'hash1',
            'raw_payload'              => [
                'offline_sequence_number' => 'OFF-T03-20260705-000001',
                'row_hash' => str_pad('hashrow1', 64, '0'),
            ],
            'status'                   => OfflineSalesImport::STATUS_SERVER_VERIFIED,
            'submitted_at'             => now(),
        ]);

        // Incoming is sequence 3 (gap detected: expected sequence 2!)
        $payload = [
            'tenant_id'                       => $this->tenant->id,
            'branch_id'                       => $this->branch->id,
            'terminal_id'                     => $this->profile->id,
            'device_id'                       => 'DEV-MOCK-UUID',
            'cashier_shift_id'                => null,
            'timecard_id'                     => null,
            'local_transaction_reference'     => 'OFF-T03-20260705-000003',
            'local_receipt_number'            => 'OFF-T03-20260705-000003',
            'business_date'                   => '2026-07-05',
            'terminal_timestamp'              => now()->toISOString(),
            'timezone'                        => 'UTC',
            'sales_machine_profile_id'        => $this->profile->id,
            'catalog_version_hash'            => str_repeat('a', 64),
            'tax_configuration_version_hash'  => str_repeat('b', 64),
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
        $rowHash = hash('sha256', $payloadHash . 'OFF-T03-20260705-000003' . 'BATCH-MOCK');

        $importPayload = array_merge($payload, [
            'offline_sequence_number'         => 'OFF-T03-20260705-000003',
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
            'previous_hash'                   => str_pad('hashrow1', 64, '0'), // chained correctly but suffix gap
        ]);

        $batchPayload = [
            'batch_reference' => 'BATCH-MOCK',
            'imports'         => [$importPayload]
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/offline-sync', $batchPayload);

        if ($response->status() !== 422 || $response->json('error') !== 'CHAIN_VALIDATION_FAILED') {
            dump($response->json());
        }
        $response->assertStatus(422);
        $this->assertEquals('CHAIN_VALIDATION_FAILED', $response->json('error'));
    }

    public function test_broken_hash_chain_blocks_batch()
    {
        // First create a parent batch record
        $prevBatch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-PREV2',
            'status'                   => 'completed',
            'submitted_import_count'  => 1,
            'sync_started_at'          => now(),
        ]);

        // First successful import in DB
        $import1 = OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $prevBatch->id,
            'offline_sequence_number'  => 'OFF-T03-20260705-000001',
            'payload_hash'             => 'hash1',
            'raw_payload'              => [
                'offline_sequence_number' => 'OFF-T03-20260705-000001',
                'row_hash' => str_pad('hashrow1', 64, '0'),
            ],
            'status'                   => OfflineSalesImport::STATUS_SERVER_VERIFIED,
            'submitted_at'             => now(),
        ]);

        // Incoming has sequence 2, but previous_hash is 'bad_hash' (should be 'hashrow1')
        $payload = [
            'tenant_id'                       => $this->tenant->id,
            'branch_id'                       => $this->branch->id,
            'terminal_id'                     => $this->profile->id,
            'device_id'                       => 'DEV-MOCK-UUID',
            'cashier_shift_id'                => null,
            'timecard_id'                     => null,
            'local_transaction_reference'     => 'OFF-T03-20260705-000002',
            'local_receipt_number'            => 'OFF-T03-20260705-000002',
            'business_date'                   => '2026-07-05',
            'terminal_timestamp'              => now()->toISOString(),
            'timezone'                        => 'UTC',
            'sales_machine_profile_id'        => $this->profile->id,
            'catalog_version_hash'            => str_repeat('a', 64),
            'tax_configuration_version_hash'  => str_repeat('b', 64),
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
        $rowHash = hash('sha256', $payloadHash . 'OFF-T03-20260705-000002' . 'BATCH-MOCK');

        $importPayload = array_merge($payload, [
            'offline_sequence_number'         => 'OFF-T03-20260705-000002',
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
            'previous_hash'                   => str_pad('bad_hash', 64, '0'), // BROKEN CHAIN!
        ]);

        $batchPayload = [
            'batch_reference' => 'BATCH-MOCK',
            'imports'         => [$importPayload]
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson('/api/pos/offline-sync', $batchPayload);

        if ($response->status() !== 422 || $response->json('error') !== 'CHAIN_VALIDATION_FAILED') {
            dump($response->json());
        }
        $response->assertStatus(422);
        $this->assertEquals('CHAIN_VALIDATION_FAILED', $response->json('error'));
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
