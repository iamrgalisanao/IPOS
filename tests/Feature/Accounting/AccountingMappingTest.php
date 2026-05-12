<?php

namespace Tests\Feature\Accounting;

use App\Http\Controllers\POSController;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\QuickBooksConnection;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\Accounting\QuickBooksPayloadBuilderService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingMappingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected AccountingMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($this->branch);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->user->assignToBranch($this->branch);
        $this->service = app(AccountingMappingService::class);

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost/accounting/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.api_base_url' => 'https://quickbooks.test',
        ]);
    }

    protected function tearDown(): void
    {
        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_tenant_can_create_account_mapping(): void
    {
        $mapping = $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
            'external_name' => 'Sales Income',
        ], $this->user);

        $this->assertSame($this->tenant->id, $mapping->tenant_id);
        $this->assertSame('sales', $mapping->pos_key);
        $this->assertSame('QB-ACCOUNT-SALES', $mapping->external_id);
        $this->assertSame($this->user->id, $mapping->created_by);
    }

    public function test_tenant_can_create_tax_code_mapping(): void
    {
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VAT12',
            'name' => 'VAT 12%',
            'tax_type' => 'vat',
            'rate' => 12,
            'status' => 'active',
        ]);

        $mapping = $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => $taxCategory->id,
            'external_id' => 'QB-TAX-STANDARD',
        ]);

        $this->assertSame($taxCategory->id, $mapping->pos_entity_id);
        $this->assertSame('QB-TAX-STANDARD', $mapping->external_id);
    }

    public function test_tenant_can_create_payment_method_mapping(): void
    {
        $paymentMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);

        $mapping = $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => $paymentMethod->id,
            'external_id' => 'QB-PM-CASH',
        ]);

        $this->assertSame($paymentMethod->id, $mapping->pos_entity_id);
        $this->assertSame('QB-PM-CASH', $mapping->external_id);
    }

    public function test_tenant_can_create_product_mapping(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $mapping = $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => $product->id,
            'external_id' => 'QB-ITEM-' . strtoupper(substr($product->id, 0, 6)),
        ]);

        $this->assertSame($product->id, $mapping->pos_entity_id);
    }

    public function test_account_mapping_resolves_by_pos_key(): void
    {
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $this->assertSame('QB-ACCOUNT-SALES', $this->service->mapAccount('sales'));
    }

    public function test_tax_mapping_resolves_by_tax_category_id(): void
    {
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VAT12',
            'name' => 'VAT 12%',
            'tax_type' => 'vat',
            'rate' => 12,
            'status' => 'active',
        ]);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => $taxCategory->id,
            'external_id' => 'QB-TAX-STANDARD',
        ]);

        $this->assertSame('QB-TAX-STANDARD', $this->service->mapTaxCode($taxCategory->id));
    }

    public function test_payment_method_mapping_resolves_by_payment_method_id(): void
    {
        $paymentMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => $paymentMethod->id,
            'external_id' => 'QB-PM-CASH',
        ]);

        $this->assertSame('QB-PM-CASH', $this->service->mapPaymentMethod($paymentMethod->id));
    }

    public function test_product_mapping_resolves_by_product_id(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => $product->id,
            'external_id' => 'QB-ITEM-PROD',
        ]);

        $this->assertSame('QB-ITEM-PROD', $this->service->mapProduct($product->id));
    }

    public function test_missing_mapping_fails_safely(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing QuickBooks mapping for payment method.');

        $this->service->mapPaymentMethod((string) Str::uuid());
    }

    public function test_tenant_cannot_resolve_another_tenant_mapping(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($otherBranch);

        app(AccountingMappingService::class)->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-OTHER-TENANT',
        ]);

        app(TenantContext::class)->setTenant($this->tenant);
        app(BranchContext::class)->setBranch($this->branch);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $this->assertSame('QB-ACCOUNT-SALES', $this->service->mapAccount('sales'));
    }

    public function test_branch_specific_mapping_overrides_tenant_level_mapping(): void
    {
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-TENANT',
        ]);

        $this->service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-BRANCH',
        ]);

        $this->assertSame('QB-ACCOUNT-BRANCH', $this->service->mapAccount('sales'));
    }

    public function test_mapping_metadata_excludes_tokens_and_secrets(): void
    {
        $mapping = $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
            'metadata' => [
                'label' => 'safe',
                'access_token' => 'secret',
                'nested' => [
                    'client_secret' => 'hidden',
                    'path' => 'kept',
                ],
            ],
        ]);

        $this->assertSame(['label' => 'safe', 'nested' => ['path' => 'kept']], $mapping->metadata);
    }

    public function test_inactive_mapping_is_not_used(): void
    {
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-INACTIVE',
            'status' => 'inactive',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing QuickBooks mapping for account.');

        $this->service->mapAccount('sales');
    }

    public function test_quickbooks_payload_builder_uses_persisted_mappings(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $paymentMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VAT12',
            'name' => 'VAT 12%',
            'tax_type' => 'vat',
            'rate' => 12,
            'status' => 'active',
        ]);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => $product->id,
            'external_id' => 'QB-ITEM-PROD',
        ]);
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => $taxCategory->id,
            'external_id' => 'QB-TAX-STANDARD',
        ]);
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => $paymentMethod->id,
            'external_id' => 'QB-PM-CASH',
        ]);

        $outbox = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => [
                'sale_number' => 'POS-001',
                'subtotal' => '100.0000',
                'tax_total' => '12.0000',
                'total' => '112.0000',
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => 'Burger',
                    'quantity' => '1.0000',
                    'unit_price' => '100.0000',
                    'line_total' => '100.0000',
                ]],
                'taxes' => [[
                    'tax_category_id' => $taxCategory->id,
                    'tax_rate' => '12.0000',
                    'tax_amount' => '12.0000',
                ]],
                'payments' => [[
                    'method' => $paymentMethod->id,
                    'amount' => '112.0000',
                ]],
            ],
            'sync_status' => 'pending',
        ]);

        $payload = app(QuickBooksPayloadBuilderService::class)->build($outbox);

        $this->assertSame('QB-ITEM-PROD', $payload['payload']['Line'][0]['SalesItemLineDetail']['ItemRef']['value']);
        $this->assertSame('QB-TAX-STANDARD', $payload['payload']['TxnTaxDetail']['TaxLine'][0]['TaxLineDetail']['TaxRateRef']['value']);
        $this->assertSame('QB-PM-CASH', $payload['payload']['PaymentMethodRef']['value']);
    }

    public function test_missing_persisted_mapping_causes_processor_failure_with_safe_error(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $paymentMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VAT12',
            'name' => 'VAT 12%',
            'tax_type' => 'vat',
            'rate' => 12,
            'status' => 'active',
        ]);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => $taxCategory->id,
            'external_id' => 'QB-TAX-STANDARD',
        ]);
        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => $paymentMethod->id,
            'external_id' => 'QB-PM-CASH',
        ]);

        QuickBooksConnection::create([
            'tenant_id' => $this->tenant->id,
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        Http::fake();

        $outbox = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => [
                'sale_number' => 'POS-001',
                'subtotal' => '100.0000',
                'tax_total' => '12.0000',
                'total' => '112.0000',
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => 'Burger',
                    'quantity' => '1.0000',
                    'unit_price' => '100.0000',
                    'line_total' => '100.0000',
                ]],
                'taxes' => [[
                    'tax_category_id' => $taxCategory->id,
                    'tax_rate' => '12.0000',
                    'tax_amount' => '12.0000',
                ]],
                'payments' => [[
                    'method' => $paymentMethod->id,
                    'amount' => '112.0000',
                ]],
            ],
            'sync_status' => 'pending',
        ]);

        app(AccountingOutboxProcessorService::class)->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertSame('failed', $fresh->sync_status);
        $this->assertSame('mapping', $fresh->sync_error_category);
        $this->assertStringContainsString('Missing QuickBooks mapping for item.', $fresh->sync_error);
        Http::assertNothingSent();
    }

    public function test_mapping_service_does_not_create_outbox_records_or_mutate_business_records(): void
    {
        $countsBefore = [
            'outbox' => AccountingOutbox::count(),
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'inventory' => InventoryMovement::count(),
            'refund' => SaleRefund::count(),
            'void' => SaleVoid::count(),
        ];

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);
        $this->service->resolveExternalId(AccountingMappingService::TYPE_ACCOUNT, posKey: 'sales');

        $countsAfter = [
            'outbox' => AccountingOutbox::count(),
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'inventory' => InventoryMovement::count(),
            'refund' => SaleRefund::count(),
            'void' => SaleVoid::count(),
        ];

        $this->assertSame($countsBefore, $countsAfter);
    }

    public function test_cashier_pos_remains_accounting_silent(): void
    {
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        $this->service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $request = Request::create('/pos', 'GET');
        $request->setUserResolver(fn () => $this->user);

        $response = app(POSController::class)->index($request)->toResponse($request);
        $content = $response->getContent();

        $this->assertStringNotContainsString('accounting_mappings', $content);
        $this->assertStringNotContainsString('QB-ACCOUNT-SALES', $content);
        $this->assertStringNotContainsString('"mapping_type":"account"', $content);
        $this->assertStringNotContainsString('"external_id":"QB-ACCOUNT-SALES"', $content);
    }
}