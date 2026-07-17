<?php

namespace Tests\Feature\POS;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncAttempt;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesMachineProfile;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncEpic41ContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $profile;
    private Product $product;
    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->profile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'MAIN',
            'machine_identification_number' => 'MIN-MAIN',
            'machine_serial_number' => 'SER-MAIN',
            'software_license_number' => 'LIC-MAIN',
            'permit_to_use_number' => 'PTU-MAIN',
            'authority_to_generate_control_number' => 'ATG-MAIN',
            'supplier_name' => 'Supplier',
            'supplier_tin' => '123-456-789-000',
            'supplier_branch_code' => '00001',
            'supplier_accreditation_number' => 'ACC-MAIN',
            'status' => 'active',
            'offline_sales_enabled' => null,
            'offline_sequence_prefix' => 'OFF-MAIN-',
            'offline_sequence_next_value' => 1,
            'offline_sequence_status' => 'active',
        ]);

        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Exempt',
            'code' => 'EXEMPT',
            'tax_type' => 'exempt',
            'rate' => 0.00,
            'status' => 'active',
            'description' => 'Exempt',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id' => $taxCategory->id,
            'selling_price' => 150.00,
            'status' => 'active',
            'is_inventory_tracked' => false,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'cash',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_offline_envelope_accepts_and_returns_epic_41_contract(): void
    {
        $payload = $this->batchPayload($this->importPayload());

        $response = $this->postSync($payload);

        $response->assertOk()
            ->assertJsonPath('contract_version', 'epic-41-sync-v1')
            ->assertJsonPath('imports.0.sync_status', 'accepted')
            ->assertJsonPath('imports.0.consequence_status.sale', 'committed')
            ->assertJsonPath('imports.0.consequence_status.payment', 'committed')
            ->assertJsonPath('imports.0.consequence_status.accounting_outbox', 'queued');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
        $this->assertSame(1, AccountingOutbox::where('event_type', 'sale_paid')->count());

        $import = OfflineSalesImport::firstOrFail();
        $this->assertSame('accepted', $import->server_sync_status);
        $this->assertSame('accepted', $import->original_sync_status);
        $this->assertNotNull($import->server_payload_fingerprint);
        $this->assertNotNull($import->acceptance_consequence_snapshot);
        $this->assertSame(1, OfflineSyncAttempt::where('offline_sales_import_id', $import->id)->count());
    }

    public function test_exact_replay_returns_replayed_without_duplicate_consequences(): void
    {
        $payload = $this->batchPayload($this->importPayload());

        $first = $this->postSync($payload);
        $second = $this->postSync($payload);

        $first->assertOk()->assertJsonPath('imports.0.sync_status', 'accepted');
        $second->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'replayed')
            ->assertJsonPath('imports.0.original_sync_status', 'accepted');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
        $this->assertSame(1, AccountingOutbox::where('event_type', 'sale_paid')->count());
        $this->assertSame(2, OfflineSyncAttempt::count());
    }

    public function test_same_uuid_with_fingerprint_drift_is_rejected_before_mutation(): void
    {
        $import = $this->importPayload();
        $this->postSync($this->batchPayload($import))->assertOk();

        $drifted = $import;
        $drifted['client_total'] = 999.00;
        $drifted['payments'][0]['amount'] = 999.00;
        unset($drifted['business_payload_fingerprint'], $drifted['payload_hash']);
        $drifted['business_payload_fingerprint'] = $this->fingerprint($drifted);
        $drifted['payload_hash'] = $drifted['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($drifted, 'BATCH-DRIFT'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_fingerprint_drift');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
    }

    public function test_status_lookup_returns_stable_result_without_resubmitting_payload(): void
    {
        $import = $this->importPayload();
        $this->postSync($this->batchPayload($import))->assertOk();

        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->getJson("/api/v1/pos/offline-sales/{$import['offline_transaction_uuid']}/sync-status")
            ->assertOk()
            ->assertJsonPath('sync_status', 'accepted')
            ->assertJsonPath('original_sync_status', 'accepted');
    }

    public function test_cash_collected_policy_violation_enters_review_required(): void
    {
        $card = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'card',
            'name' => 'Card',
            'type' => 'card',
            'status' => 'active',
        ]);

        $import = $this->importPayload([
            'payments' => [[
                'payment_method_id' => $card->id,
                'amount' => 300.00,
            ]],
            'cash_status' => 'collected',
        ]);

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'rejected_non_cash_tender');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_offline_uuid_is_rejected_without_creating_sale(): void
    {
        $import = $this->importPayload();
        unset($import['offline_transaction_uuid'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_missing_offline_transaction_uuid');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_terminal_binding_epoch_is_rejected_without_creating_sale(): void
    {
        $import = $this->importPayload();
        unset($import['terminal_binding_epoch'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_missing_terminal_binding_epoch');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_legacy_payload_hash_is_used_for_fingerprint_validation(): void
    {
        $import = $this->importPayload();
        unset($import['business_payload_fingerprint']);

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'accepted');

        $this->assertSame(1, Sale::count());
    }

    public function test_conflicting_fingerprint_aliases_are_rejected_without_mutation(): void
    {
        $import = $this->importPayload([
            'payload_hash' => str_repeat('f', 64),
        ]);

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_fingerprint_evidence_conflict');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_rejected_envelope_does_not_abort_accepted_sibling(): void
    {
        $accepted = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000010',
            'local_sequence' => '10',
        ]);
        $rejected = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000011',
            'local_sequence' => '11',
        ]);
        unset($rejected['terminal_binding_epoch'], $rejected['business_payload_fingerprint'], $rejected['payload_hash']);
        $rejected['business_payload_fingerprint'] = $this->fingerprint($rejected);
        $rejected['payload_hash'] = $rejected['business_payload_fingerprint'];

        $this->postSync([
            'batch_reference' => 'BATCH-MIXED',
            'imports' => [$accepted, $rejected],
        ])->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'accepted')
            ->assertJsonPath('imports.1.sync_status', 'rejected')
            ->assertJsonPath('imports.1.reason', 'rejected_missing_terminal_binding_epoch');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
    }

    public function test_missing_payment_method_creates_no_sale(): void
    {
        $import = $this->importPayload();
        unset($import['payment_method'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'rejected_non_cash_tender');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_payments_create_no_sale_or_payment_configuration(): void
    {
        $methodCount = PaymentMethod::count();
        $import = $this->importPayload();
        unset($import['payments'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'rejected_missing_payment_evidence');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame($methodCount, PaymentMethod::count());
    }

    public function test_empty_payments_create_no_sale(): void
    {
        $import = $this->importPayload();
        $import['payments'] = [];
        unset($import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertStatus(422);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_payment_total_mismatch_creates_no_sale_payment_or_config(): void
    {
        $methodCount = PaymentMethod::count();
        $import = $this->importPayload();
        $import['payments'][0]['amount'] = 250.00;
        unset($import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'validation_failed');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame($methodCount, PaymentMethod::count());
    }

    public function test_missing_active_cash_method_does_not_auto_create_configuration(): void
    {
        $this->cashMethod->update(['status' => 'inactive']);
        $methodCount = PaymentMethod::count();
        $import = $this->importPayload();

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_required_cash_payment_configuration');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame($methodCount, PaymentMethod::count());
    }

    private function postSync(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/v1/pos/offline-sales/sync', $payload);
    }

    private function batchPayload(array $import, string $reference = 'BATCH-EPIC-41'): array
    {
        return [
            'batch_reference' => $reference,
            'imports' => [$import],
        ];
    }

    private function importPayload(array $overrides = []): array
    {
        $uuid = $overrides['offline_transaction_uuid'] ?? Str::uuid()->toString();
        $payload = array_replace_recursive([
            'offline_transaction_uuid' => $uuid,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'terminal_id' => $this->profile->id,
            'sales_machine_profile_id' => $this->profile->id,
            'terminal_binding_epoch' => 'epoch-1',
            'offline_sequence_number' => 'OFF-MAIN-000001',
            'local_sequence' => '1',
            'user_id' => $this->cashier->id,
            'cashier_id' => $this->cashier->id,
            'submitted_at' => '2026-07-17T08:00:00+08:00',
            'terminal_timestamp' => '2026-07-17T08:00:00+08:00',
            'timezone' => 'Asia/Manila',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 150.00,
            ]],
            'client_subtotal' => 300.00,
            'client_tax_total' => 0.00,
            'client_total' => 300.00,
            'payment_method' => 'cash',
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 300.00,
            ]],
            'catalog_version_hash' => str_repeat('a', 64),
            'tax_configuration_version_hash' => str_repeat('b', 64),
            'payment_methods_version_hash' => str_repeat('c', 64),
            'terminal_policy_version_hash' => str_repeat('d', 64),
            'sync_attempt_id' => Str::uuid()->toString(),
            'lease_id' => Str::uuid()->toString(),
            'attempt_generation' => 1,
            'queue_state_revision' => 1,
            'cash_status' => 'collected',
        ], $overrides);

        unset($payload['include_client_fingerprint']);
        $payload['business_payload_fingerprint'] = $this->fingerprint($payload);
        $payload['payload_hash'] = $overrides['payload_hash'] ?? $payload['business_payload_fingerprint'];

        return $payload;
    }

    private function fingerprint(array $payload): string
    {
        $material = array_intersect_key($payload, array_flip([
            'tenant_id',
            'branch_id',
            'terminal_id',
            'sales_machine_profile_id',
            'terminal_binding_epoch',
            'offline_transaction_uuid',
            'offline_sequence_number',
            'local_sequence',
            'user_id',
            'cashier_id',
            'cashier_shift_id',
            'drawer_session_id',
            'items',
            'client_subtotal',
            'client_tax_total',
            'client_total',
            'payment_method',
            'payments',
            'catalog_version_hash',
            'tax_configuration_version_hash',
            'payment_methods_version_hash',
            'terminal_policy_version_hash',
            'submitted_at',
            'terminal_timestamp',
            'timezone',
        ]));

        return hash('sha256', $this->canonicalJson($material));
    }

    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_decode($this->canonicalJson($value), true);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
