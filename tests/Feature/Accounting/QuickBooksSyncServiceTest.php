<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\QuickBooksSyncService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickBooksSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost/accounting/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.api_base_url' => 'https://quickbooks.test',
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

        $this->seedMappings();
    }

    protected function tearDown(): void
    {
        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_sync_creates_sales_receipt_and_returns_external_reference(): void
    {
        Http::fake([
            'quickbooks.test/*' => Http::response([
                'SalesReceipt' => [
                    'Id' => 'QB-123',
                ],
            ], 200),
        ]);

        $result = app(QuickBooksSyncService::class)->sync($this->createOutbox('sale_paid', [
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
        ]));

        $this->assertSame('quickbooks', $result['external_provider']);
        $this->assertSame('QB-123', $result['external_id']);
        $this->assertSame('SalesReceipt:QB-123', $result['external_reference']);

        Http::assertSent(fn($request) =>
            $request->hasHeader('Authorization', 'Bearer access-token')
            && str_contains($request->url(), '/v3/company/realm-123/salesreceipt')
            && $request['DocNumber'] === 'POS-001'
        );
    }

    public function test_sync_voids_existing_sales_receipt_by_document_number(): void
    {
        Http::fake([
            'quickbooks.test/*' => function ($request) {
                if (str_contains($request->url(), '/query')) {
                    return Http::response([
                        'QueryResponse' => [
                            'SalesReceipt' => [[
                                'Id' => 'QB-789',
                                'SyncToken' => '3',
                                'DocNumber' => 'POS-001',
                            ]],
                        ],
                    ], 200);
                }

                return Http::response([
                    'SalesReceipt' => [
                        'Id' => 'QB-789',
                    ],
                ], 200);
            },
        ]);

        $result = app(QuickBooksSyncService::class)->sync($this->createOutbox('sale_voided', [
            'sale_number' => 'POS-001',
            'total' => '112.0000',
            'payment_reversals' => [[
                'payment_method_id' => 'cash-method-uuid',
                'amount' => '112.0000',
            ]],
        ]));

        $this->assertSame('QB-789', $result['external_id']);
        $this->assertSame('SalesReceipt:QB-789', $result['external_reference']);

        Http::assertSent(fn($request) =>
            str_contains($request->url(), '/query')
            && str_contains(urldecode($request->url()), "DocNumber = 'POS-001'")
        );

        Http::assertSentCount(2);
    }

    public function test_sync_creates_refund_receipt_and_returns_external_reference(): void
    {
        Http::fake([
            'quickbooks.test/*' => Http::response([
                'RefundReceipt' => [
                    'Id' => 'QB-REF-1',
                ],
            ], 200),
        ]);

        $result = app(QuickBooksSyncService::class)->sync($this->createOutbox('sale_refunded', [
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
        ]));

        $this->assertSame('quickbooks', $result['external_provider']);
        $this->assertSame('QB-REF-1', $result['external_id']);
        $this->assertSame('RefundReceipt:QB-REF-1', $result['external_reference']);

        Http::assertSent(fn($request) =>
            str_contains($request->url(), '/v3/company/realm-123/refundreceipt')
            && !str_contains(json_encode($request->data()), 'access_token')
            && !str_contains(json_encode($request->data()), 'refresh_token')
        );
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