<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Accounting\Contracts\AccountingMapperInterface;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\NormalizedPayloadService;
use App\Services\Accounting\QuickBooksPayloadBuilderService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class QuickBooksPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->seedMappings();
    }

    protected function tearDown(): void
    {
        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_sale_paid_builds_quickbooks_sales_receipt_payload(): void
    {
        $outbox = $this->createOutbox('sale_paid', [
            'sale_number' => 'POS-001',
            'subtotal' => '100.0000',
            'tax_total' => '12.0000',
            'total' => '112.0000',
            'items' => [[
                'product_id' => 'prod-uuid',
                'product_name' => 'Burger',
                'quantity' => '1.0000',
                'unit_price' => '100.0000',
                'line_total' => '100.0000',
            ]],
            'taxes' => [[
                'tax_category_id' => 'tax-uuid',
                'tax_rate' => '12.0000',
                'tax_amount' => '12.0000',
            ]],
            'payments' => [[
                'method' => 'cash-method-uuid',
                'amount' => '112.0000',
            ]],
            'amount_tendered' => '200.0000',
            'change_due' => '88.0000',
        ]);

        $payload = app(QuickBooksPayloadBuilderService::class)->build($outbox);

        $this->assertEquals('quickbooks', $payload['provider']);
        $this->assertEquals('SalesReceipt', $payload['entity']);
        $this->assertEquals('create', $payload['operation']);
        $this->assertEquals($this->tenant->id, $payload['tenant_id']);
        $this->assertEquals($this->branch->id, $payload['branch_id']);
        $this->assertEquals('POS-001', $payload['payload']['DocNumber']);
        $this->assertEquals(112.00, $payload['payload']['TotalAmt']);
        $this->assertEquals('QB-ITEM-PROD', $payload['payload']['Line'][0]['SalesItemLineDetail']['ItemRef']['value']);
        $this->assertEquals('QB-TAX-STANDARD', $payload['payload']['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value']);
        $this->assertEquals('QB-PM-CASH', $payload['payload']['PaymentMethodRef']['value']);

        $json = json_encode($payload);
        $this->assertStringNotContainsString('amount_tendered', $json);
        $this->assertStringNotContainsString('change_due', $json);
    }

    public function test_sale_refunded_builds_quickbooks_refund_receipt_payload(): void
    {
        $outbox = $this->createOutbox('sale_refunded', [
            'refund_number' => 'REF-001',
            'refund_total' => '35.0000',
            'refund_items' => [[
                'product_id' => 'prod-uuid',
                'quantity_refunded' => '1.0000',
                'unit_price_snapshot' => '35.0000',
                'line_refund_total' => '35.0000',
            ]],
            'payment_reversals' => [[
                'payment_method_id' => 'gcash-method-uuid',
                'amount' => '35.0000',
            ]],
        ]);

        $payload = app(QuickBooksPayloadBuilderService::class)->build($outbox);

        $this->assertEquals('RefundReceipt', $payload['entity']);
        $this->assertEquals('create', $payload['operation']);
        $this->assertEquals('REF-001', $payload['payload']['DocNumber']);
        $this->assertEquals(35.00, $payload['payload']['TotalAmt']);
        $this->assertEquals('QB-ITEM-PROD', $payload['payload']['Line'][0]['SalesItemLineDetail']['ItemRef']['value']);
        $this->assertEquals('QB-PM-GCASH', $payload['payload']['PaymentMethodRef']['value']);
    }

    public function test_sale_voided_builds_quickbooks_void_command_payload(): void
    {
        $outbox = $this->createOutbox('sale_voided', [
            'sale_number' => 'POS-001',
            'total' => '112.0000',
            'payment_reversals' => [[
                'payment_method_id' => 'cash-method-uuid',
                'amount' => '112.0000',
            ]],
        ]);

        $payload = app(QuickBooksPayloadBuilderService::class)->build($outbox);

        $this->assertEquals('SalesReceipt', $payload['entity']);
        $this->assertEquals('void', $payload['operation']);
        $this->assertEquals('POS-001', $payload['payload']['DocNumber']);
        $this->assertEquals(112.00, $payload['payload']['TotalAmt']);
        $this->assertEquals('QB-PM-CASH', $payload['payload']['PaymentReversals'][0]['PaymentMethodRef']['value']);
    }

    public function test_builder_rejects_cross_tenant_outbox_record(): void
    {
        $outbox = $this->createOutbox('sale_voided', [
            'sale_number' => 'POS-001',
            'total' => '112.0000',
            'payment_reversals' => [],
        ]);

        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another tenant');

        app(QuickBooksPayloadBuilderService::class)->build($outbox);
    }

    public function test_builder_surfaces_missing_mapping_errors(): void
    {
        $outbox = $this->createOutbox('sale_paid', [
            'sale_number' => 'POS-001',
            'subtotal' => '100.0000',
            'tax_total' => '0.0000',
            'total' => '100.0000',
            'items' => [[
                'product_id' => 'prod-uuid',
                'product_name' => 'Burger',
                'quantity' => '1.0000',
                'unit_price' => '100.0000',
                'line_total' => '100.0000',
            ]],
            'payments' => [],
        ]);

        $builder = new QuickBooksPayloadBuilderService(
            app(TenantContext::class),
            app(BranchContext::class),
            new NormalizedPayloadService(new MissingProductMapper())
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing QuickBooks mapping for item.');

        $builder->build($outbox);
    }

    protected function createOutbox(string $eventType, array $payload): AccountingOutbox
    {
        return AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => $eventType,
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => $payload,
            'sync_status' => 'pending',
        ]);
    }

    protected function seedMappings(): void
    {
        $service = app(AccountingMappingService::class);

        $service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => 'prod-uuid',
            'external_id' => 'QB-ITEM-PROD',
        ]);

        $service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => 'tax-uuid',
            'external_id' => 'QB-TAX-STANDARD',
        ]);

        $service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => 'cash-method-uuid',
            'external_id' => 'QB-PM-CASH',
        ]);

        $service->createOrUpdate([
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => 'gcash-method-uuid',
            'external_id' => 'QB-PM-GCASH',
        ]);
    }
}

class MissingProductMapper implements AccountingMapperInterface
{
    public function mapAccount(string $type): string
    {
        return 'ACCOUNT_' . strtoupper($type);
    }

    public function mapTaxCode(string $posTaxCategoryId): string
    {
        return 'TAX_CODE';
    }

    public function mapPaymentMethod(string $posPaymentMethodId): string
    {
        return 'PAYMENT_METHOD';
    }

    public function mapProduct(?string $posProductId): ?string
    {
        return null;
    }

    public function mapCustomer(?string $posCustomerId): ?string
    {
        return 'CUSTOMER';
    }

    public function mapSupplier(?string $posSupplierId): ?string
    {
        return null;
    }
}
