<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchPaymentMethodSetting;
use App\Models\OfflineSalesImport;
use App\Models\PaymentMethod;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenants;

class BranchPaymentPolicyTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants;

    protected Branch $branch;
    protected SalesMachineProfile $profile;
    protected User $adminUser;
    protected User $cashierUser;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $cardMethod;
    protected \App\Models\ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'subdomain' => 'test']);
        $this->setupTenantContext($this->tenant);

        $this->branch = Branch::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Alpha',
            'branch_code' => 'ALPHA',
            'status' => 'active'
        ]);

        $this->profile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'TERM01',
            'terminal_identifier' => 'TERM-0001',
            'offline_sales_enabled' => true,
            'offline_sequence_prefix' => 'OFF-TERM01-',
            'status' => 'active'
        ]);

        $this->category = \App\Models\ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN',
            'status' => 'active'
        ]);

        // Seed Payment Methods
        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'reference_required' => false,
            'status' => 'active'
        ]);

        $this->cardMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARD',
            'name' => 'Card',
            'type' => 'card',
            'reference_required' => true,
            'status' => 'active'
        ]);

        // Create Users
        $this->adminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'admin@test.com'
        ]);
        $this->givePermissionTo($this->adminUser, 'manage_payment_methods');

        $this->cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'cashier@test.com'
        ]);
    }

    public function test_non_admin_cannot_access_or_update_branch_payment_settings()
    {
        // 1. Unauthenticated gets 302/401
        $this->get(route('admin.branches.payment-settings.edit', $this->branch))
            ->assertRedirect(route('login'));

        // 2. Cashier (unauthorized user) gets 403
        $this->actingAs($this->cashierUser)
            ->get(route('admin.branches.payment-settings.edit', $this->branch))
            ->assertStatus(403);

        $this->actingAs($this->cashierUser)
            ->post(route('admin.branches.payment-settings.update', $this->branch), ['settings' => []])
            ->assertStatus(403);
    }

    public function test_admin_can_view_branch_payment_settings_with_fallback_defaults()
    {
        $this->actingAs($this->adminUser);

        $response = $this->get(route('admin.branches.payment-settings.edit', $this->branch));
        $response->assertStatus(200);

        // Assert Inertia props contain resolved methods with defaults
        $methods = $response->original->getData()['page']['props']['paymentMethods'];
        $this->assertCount(2, $methods);

        $cashSettings = collect($methods)->firstWhere('code', 'CASH');
        $this->assertTrue($cashSettings['allow_offline']);
        $this->assertNull($cashSettings['offline_max_limit_centavos']);

        $cardSettings = collect($methods)->firstWhere('code', 'CARD');
        $this->assertFalse($cardSettings['allow_offline']);
    }

    public function test_enabling_offline_for_card_fails_unless_type_is_cash_or_custom()
    {
        $this->actingAs($this->adminUser);

        $payload = [
            'settings' => [
                [
                    'payment_method_id' => $this->cardMethod->id,
                    'enabled' => true,
                    'allow_offline' => true, // Invalid for type card!
                    'offline_max_limit_centavos' => null,
                    'requires_reference' => true,
                    'sort_order' => 1,
                    'offline_policy_note' => 'Card offline'
                ]
            ]
        ];

        $response = $this->post(route('admin.branches.payment-settings.update', $this->branch), $payload);
        $response->assertSessionHasErrors('settings');
    }

    public function test_admin_can_configure_cash_offline_limit_and_writes_audit_log()
    {
        $this->actingAs($this->adminUser);

        $payload = [
            'settings' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'enabled' => true,
                    'allow_offline' => true,
                    'offline_max_limit_centavos' => 500000, // ₱5,000.00
                    'requires_reference' => false,
                    'sort_order' => 0,
                    'offline_policy_note' => 'Limit cash offline'
                ]
            ]
        ];

        $response = $this->post(route('admin.branches.payment-settings.update', $this->branch), $payload);
        $response->assertRedirect();

        // Verify DB record
        $this->assertDatabaseHas('branch_payment_method_settings', [
            'branch_id' => $this->branch->id,
            'payment_method_id' => $this->cashMethod->id,
            'allow_offline' => true,
            'offline_max_limit_centavos' => 500000,
        ]);

        // Verify audit log
        $log = DB::table('audit_logs')
            ->where('action', 'branch_payment_method_settings_updated')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Updated payment overrides', $log->remarks);
    }

    public function test_payment_methods_version_hash_changes_on_policy_updates()
    {
        $bootstrap = app(CacheBootstrapService::class);

        // 1. Initial default hash
        $hash1 = $bootstrap->calculatePaymentMethodsVersionHash($this->tenant->id, $this->branch->id);

        // 2. Create override settings
        BranchPaymentMethodSetting::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'payment_method_id' => $this->cashMethod->id,
            'allow_offline' => true,
            'offline_max_limit_centavos' => 100000 // ₱1,000.00
        ]);

        // 3. New hash should be different
        $hash2 = $bootstrap->calculatePaymentMethodsVersionHash($this->tenant->id, $this->branch->id);
        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_bootstrap_payload_returns_resolved_methods_list()
    {
        // Add override setting
        BranchPaymentMethodSetting::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'payment_method_id' => $this->cashMethod->id,
            'allow_offline' => true,
            'offline_max_limit_centavos' => 250000
        ]);

        $bootstrap = app(CacheBootstrapService::class);
        $payload = $bootstrap->generatePayload($this->tenant, $this->branch, $this->adminUser, $this->profile);

        $this->assertArrayHasKey('payment_methods', $payload);
        $methods = $payload['payment_methods'];

        $cash = collect($methods)->firstWhere('code', 'CASH');
        $this->assertEquals(250000, $cash['offline_max_limit_centavos']);
    }

    public function test_sync_quarantines_sale_with_non_offline_eligible_tenders()
    {
        $bootstrap = app(CacheBootstrapService::class);
        $hash = $bootstrap->calculatePaymentMethodsVersionHash($this->tenant->id, $this->branch->id);

        // Build import payload with GCash / Card (allow_offline is false by default)
        $rawPayload = [
            'offline_sequence_number' => 'OFF-TERM01-1',
            'submitted_at' => now()->toIso8601String(),
            'local_created_at' => now()->toIso8601String(),
            'payment_methods_version_hash' => $hash,
            'client_subtotal' => 100.00,
            'client_tax_total' => 0.00,
            'client_total' => 100.00,
            'items' => [
                [
                    'product_id' => '00000000-0000-0000-0000-000000000000', // product will fail, so let's create a valid product
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->cardMethod->id,
                    'amount' => 100.00,
                    'reference_number' => 'REF123'
                ]
            ]
        ];

        // Create product to pass product_not_found check
        $product = \App\Models\Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Product X',
            'sku' => 'PROD-X',
            'selling_price' => 100.00,
            'status' => 'active',
            'is_sellable' => true
        ]);
        $rawPayload['items'][0]['product_id'] = $product->id;
        $rawPayload['items'][0]['quantity'] = 1;
        $rawPayload['items'][0]['unit_price'] = 100.00;

        $reconciler = app(OfflineReconciliationService::class);
        $batchPayload = [
            'batch_reference' => 'BATCH01',
            'imports' => [$rawPayload]
        ];

        $reconciler->receiveImportBatch($this->profile, $batchPayload);

        // Fetch processed import
        $processed = OfflineSalesImport::where('offline_sequence_number', 'OFF-TERM01-1')->first();
        $this->assertEquals(OfflineSalesImport::STATUS_CONFLICT, $processed->status);

        $notes = json_decode($processed->conflict_notes, true);
        $this->assertEquals('review_required', $notes['sync_status']);
        $this->assertEquals('OFFLINE_PAYMENT_METHOD_NOT_ALLOWED', $notes['review_reason']);
    }

    public function test_sync_quarantines_sale_exceeding_offline_limit()
    {
        // 1. Set offline limit to ₱500.00 (50000 centavos)
        BranchPaymentMethodSetting::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'payment_method_id' => $this->cashMethod->id,
            'allow_offline' => true,
            'offline_max_limit_centavos' => 50000
        ]);

        $bootstrap = app(CacheBootstrapService::class);
        $hash = $bootstrap->calculatePaymentMethodsVersionHash($this->tenant->id, $this->branch->id);

        $product = \App\Models\Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Heavy Product',
            'sku' => 'PROD-HEAVY',
            'selling_price' => 600.00, // Above limit!
            'status' => 'active',
            'is_sellable' => true
        ]);

        $rawPayload = [
            'offline_sequence_number' => 'OFF-TERM01-2',
            'submitted_at' => now()->toIso8601String(),
            'local_created_at' => now()->toIso8601String(),
            'payment_methods_version_hash' => $hash,
            'client_subtotal' => 600.00,
            'client_tax_total' => 0.00,
            'client_total' => 600.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 600.00
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 600.00,
                    'amount_tendered' => 600.00
                ]
            ]
        ];

        $reconciler = app(OfflineReconciliationService::class);
        $batchPayload = [
            'batch_reference' => 'BATCH02',
            'imports' => [$rawPayload]
        ];

        $reconciler->receiveImportBatch($this->profile, $batchPayload);

        $processed = OfflineSalesImport::where('offline_sequence_number', 'OFF-TERM01-2')->first();
        $this->assertEquals(OfflineSalesImport::STATUS_CONFLICT, $processed->status);

        $notes = json_decode($processed->conflict_notes, true);
        $this->assertEquals('OFFLINE_PAYMENT_LIMIT_EXCEEDED', $notes['review_reason']);
    }

    public function test_sync_quarantines_sale_missing_required_reference()
    {
        // Create custom offline tender requiring reference
        $customMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VOUCHER',
            'name' => 'Branch Voucher',
            'type' => 'custom',
            'reference_required' => false,
            'status' => 'active'
        ]);

        BranchPaymentMethodSetting::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'payment_method_id' => $customMethod->id,
            'allow_offline' => true,
            'requires_reference' => true // Required offline!
        ]);

        $bootstrap = app(CacheBootstrapService::class);
        $hash = $bootstrap->calculatePaymentMethodsVersionHash($this->tenant->id, $this->branch->id);

        $product = \App\Models\Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Item X',
            'sku' => 'ITEM-X',
            'selling_price' => 50.00,
            'status' => 'active',
            'is_sellable' => true
        ]);

        $rawPayload = [
            'offline_sequence_number' => 'OFF-TERM01-3',
            'submitted_at' => now()->toIso8601String(),
            'local_created_at' => now()->toIso8601String(),
            'payment_methods_version_hash' => $hash,
            'client_subtotal' => 50.00,
            'client_tax_total' => 0.00,
            'client_total' => 50.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 50.00
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $customMethod->id,
                    'amount' => 50.00,
                    'reference_number' => '' // Missing reference number!
                ]
            ]
        ];

        $reconciler = app(OfflineReconciliationService::class);
        $batchPayload = [
            'batch_reference' => 'BATCH03',
            'imports' => [$rawPayload]
        ];

        $reconciler->receiveImportBatch($this->profile, $batchPayload);

        $processed = OfflineSalesImport::where('offline_sequence_number', 'OFF-TERM01-3')->first();
        $this->assertEquals(OfflineSalesImport::STATUS_CONFLICT, $processed->status);

        $notes = json_decode($processed->conflict_notes, true);
        $this->assertEquals('OFFLINE_PAYMENT_REFERENCE_REQUIRED', $notes['review_reason']);
    }

    public function test_sync_accepts_with_warning_on_stale_payment_policy_hash()
    {
        $bootstrap = app(CacheBootstrapService::class);

        $product = \App\Models\Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Item X',
            'sku' => 'ITEM-X',
            'selling_price' => 50.00,
            'status' => 'active',
            'is_sellable' => true
        ]);

        $rawPayload = [
            'offline_sequence_number' => 'OFF-TERM01-4',
            'submitted_at' => now()->toIso8601String(),
            'local_created_at' => now()->toIso8601String(),
            'payment_methods_version_hash' => 'stale_hash_value_123', // STALE!
            'client_subtotal' => 50.00,
            'client_tax_total' => 0.00,
            'client_total' => 50.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 50.00
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 50.00,
                    'amount_tendered' => 50.00
                ]
            ]
        ];

        $reconciler = app(OfflineReconciliationService::class);
        $batchPayload = [
            'batch_reference' => 'BATCH04',
            'imports' => [$rawPayload]
        ];

        $reconciler->receiveImportBatch($this->profile, $batchPayload);

        $processed = OfflineSalesImport::where('offline_sequence_number', 'OFF-TERM01-4')->first();
        // Since cash is allowed, it resolves with warning due to stale hash
        $this->assertEquals(OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING, $processed->status);
        $this->assertStringContainsString('PAYMENT_POLICY_HASH_STALE', $processed->rejection_reason);
    }
}
