<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Tenant;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\NormalizedPayloadService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayloadNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected NormalizedPayloadService $normalizationService;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        $this->normalizationService = app(NormalizedPayloadService::class);
        $this->seedMappings();
    }

    protected function createOutbox(string $eventType, array $payload): AccountingOutbox
    {
        $branch = \App\Models\Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        return AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'event_type' => $eventType,
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => $payload,
            'sync_status' => 'pending'
        ]);
    }

    /** AC 1-5: sale_paid normalizes correctly */
    public function test_sale_paid_normalizes_to_standard_event(): void
    {
        $payload = [
            'sale_number' => 'POS-001',
            'subtotal' => '100.0000',
            'tax_total' => '12.0000',
            'total' => '112.0000',
            'items' => [
                [
                    'product_id' => 'prod-uuid',
                    'product_name' => 'Burger',
                    'quantity' => '1.0000',
                    'unit_price' => '100.0000',
                    'line_total' => '100.0000'
                ]
            ],
            'taxes' => [
                [
                    'tax_category_id' => 'tax-uuid',
                    'tax_rate' => '12.0000',
                    'tax_amount' => '12.0000'
                ]
            ],
            'payments' => [
                [
                    'method' => 'cash-method-uuid',
                    'amount' => '112.0000'
                ]
            ]
        ];

        $outbox = $this->createOutbox('sale_paid', $payload);
        $normalized = $this->normalizationService->normalize($outbox);

        // Header
        $this->assertEquals('sales_receipt', $normalized['header']['document_type']);
        $this->assertEquals('POS-001', $normalized['header']['document_number']);
        $this->assertEquals('100.0000', $normalized['header']['subtotal']);
        $this->assertEquals('112.0000', $normalized['header']['total']);

        // Lines
        $this->assertCount(1, $normalized['lines']);
        $this->assertEquals('QB-ITEM-PROD', $normalized['lines'][0]['mapped_item_id']);
        $this->assertEquals('QB-ACCOUNT-SALES', $normalized['lines'][0]['income_account']);

        // Taxes
        $this->assertCount(1, $normalized['taxes']);
        $this->assertEquals('QB-TAX-STANDARD', $normalized['taxes'][0]['mapped_tax_code']);

        // Payments
        $this->assertCount(1, $normalized['payments']);
        $this->assertEquals('QB-PM-CASH', $normalized['payments'][0]['mapped_payment_method']);
    }

    /** AC 6-7: sale_voided normalizes correctly */
    public function test_sale_voided_normalizes_to_reversal(): void
    {
        $payload = [
            'sale_number' => 'POS-001',
            'total' => '112.0000',
            'payment_reversals' => [
                [
                    'payment_method_id' => 'cash-method-uuid',
                    'amount' => '112.0000'
                ]
            ]
        ];

        $outbox = $this->createOutbox('sale_voided', $payload);
        $normalized = $this->normalizationService->normalize($outbox);

        $this->assertEquals('void_reversal', $normalized['header']['document_type']);
        $this->assertEquals('112.0000', $normalized['header']['total']);
        $this->assertCount(1, $normalized['payments']);
        $this->assertEquals('QB-PM-CASH', $normalized['payments'][0]['mapped_payment_method']);
    }

    /** AC 8-9: sale_refunded normalizes correctly */
    public function test_sale_refunded_normalizes_to_refund(): void
    {
        $payload = [
            'refund_number' => 'REF-001',
            'refund_total' => '50.0000',
            'refund_items' => [
                [
                    'product_id' => 'prod-uuid',
                    'quantity_refunded' => '0.5000',
                    'unit_price_snapshot' => '100.0000',
                    'line_refund_total' => '50.0000'
                ]
            ],
            'payment_reversals' => [
                [
                    'payment_method_id' => 'cash-method-uuid',
                    'amount' => '50.0000'
                ]
            ]
        ];

        $outbox = $this->createOutbox('sale_refunded', $payload);
        $normalized = $this->normalizationService->normalize($outbox);

        $this->assertEquals('refund_credit', $normalized['header']['document_type']);
        $this->assertEquals('50.0000', $normalized['header']['refund_total']);
        $this->assertCount(1, $normalized['lines']);
        $this->assertEquals('QB-ITEM-PROD', $normalized['lines'][0]['mapped_item_id']);
    }

    /** AC 10: Decimal values remain strings */
    public function test_decimals_remain_strings(): void
    {
        $payload = [
            'sale_number' => 'POS-001',
            'subtotal' => '100.0000',
            'tax_total' => '12.0000',
            'total' => '112.0000',
            'items' => [['quantity' => '1.0000', 'unit_price' => '100.0000', 'line_total' => '100.0000']]
        ];

        $outbox = $this->createOutbox('sale_paid', $payload);
        $normalized = $this->normalizationService->normalize($outbox);

        $this->assertIsString($normalized['header']['subtotal']);
        $this->assertIsString($normalized['lines'][0]['quantity']);
    }

    /** AC 14-16: Payload excludes secrets and UI-only fields */
    public function test_payload_excludes_sensitive_or_ui_fields(): void
    {
        $payload = [
            'sale_number' => 'POS-001',
            'subtotal' => '100.0000',
            'tax_total' => '12.0000',
            'total' => '112.0000',
            'cost_price' => '50.0000', // Should be excluded
            'amount_tendered' => '120.0000', // Should be excluded
            'change_due' => '8.0000', // Should be excluded
            'provider_token' => 'secret', // Should be excluded
        ];

        $outbox = $this->createOutbox('sale_paid', $payload);
        $normalized = $this->normalizationService->normalize($outbox);

        $json = json_encode($normalized);
        $this->assertStringNotContainsString('cost_price', $json);
        $this->assertStringNotContainsString('amount_tendered', $json);
        $this->assertStringNotContainsString('provider_token', $json);
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
    }
}
