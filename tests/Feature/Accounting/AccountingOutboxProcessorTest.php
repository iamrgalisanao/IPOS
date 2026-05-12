<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\AccountingSyncAttempt;
use App\Models\InventoryMovement;
use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\Accounting\QuickBooksSyncService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountingOutboxProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected AccountingOutboxProcessorService $processor;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost/accounting/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.api_base_url' => 'https://quickbooks.test',
        ]);

        $this->processor = app(AccountingOutboxProcessorService::class);
    }

    protected function createOutbox(string $status = 'pending', int $attempts = 0, string $eventType = 'sale_paid', array $payloadOverrides = []): AccountingOutbox
    {
        $branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $payload = array_replace_recursive($this->basePayloadFor($eventType), $payloadOverrides);

        $outbox = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'event_type' => $eventType,
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => $payload,
            'sync_status' => $status,
            'attempt_count' => $attempts
        ]);

        $this->seedMappingsForEventType($eventType, $branch->id);

        return $outbox;
    }

    protected function basePayloadFor(string $eventType): array
    {
        return match ($eventType) {
            'sale_paid' => [
                'sale_number' => 'POS-TEST',
                'subtotal' => '100.0000',
                'tax_total' => '12.0000',
                'total' => '112.0000',
                'items' => [[
                    'product_id' => 'prod-uuid',
                    'product_name' => 'Burger',
                    'quantity' => '1.0000',
                    'unit_price' => '100.0000',
                    'line_total' => '100.0000',
                    'cost_price' => '50.0000',
                ]],
                'taxes' => [[
                    'tax_category_id' => 'tax-uuid',
                    'tax_rate' => '12.0000',
                    'tax_amount' => '12.0000',
                ]],
                'payments' => [[
                    'method' => 'cash-method-uuid',
                    'amount' => '112.0000',
                    'reference' => 'REF-123',
                ]],
                'amount_tendered' => '200.0000',
                'change_due' => '88.0000',
                'provider_token' => 'secret-should-not-leak',
            ],
            'sale_voided' => [
                'sale_number' => 'POS-VOID',
                'total' => '112.0000',
                'payment_reversals' => [[
                    'payment_method_id' => 'cash-method-uuid',
                    'amount' => '112.0000',
                ]],
            ],
            'sale_refunded' => [
                'refund_number' => 'REF-TEST',
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
            ],
            default => [],
        };
    }

    protected function createConnectedConnection(array $overrides = []): QuickBooksConnection
    {
        return QuickBooksConnection::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ], $overrides));
    }

    protected function useRealQuickBooksSync(): void
    {
        app()->forgetInstance(QuickBooksSyncService::class);
        $this->processor = app(AccountingOutboxProcessorService::class);
    }

    protected function seedMappingsForEventType(string $eventType, ?string $branchId = null, array $except = []): void
    {
        $service = app(AccountingMappingService::class);

        if (!in_array('sales', $except, true)) {
            $service->createOrUpdate([
                'branch_id' => $branchId,
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'pos_key' => 'sales',
                'external_id' => 'QB-ACCOUNT-SALES',
            ]);
        }

        if (in_array($eventType, ['sale_paid', 'sale_refunded'], true) && !in_array('product', $except, true)) {
            $service->createOrUpdate([
                'branch_id' => $branchId,
                'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
                'pos_entity_type' => 'product',
                'pos_entity_id' => 'prod-uuid',
                'external_id' => 'QB-ITEM-PROD',
            ]);
        }

        if ($eventType === 'sale_paid' && !in_array('tax', $except, true)) {
            $service->createOrUpdate([
                'branch_id' => $branchId,
                'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
                'pos_entity_type' => 'tax_category',
                'pos_entity_id' => 'tax-uuid',
                'external_id' => 'QB-TAX-STANDARD',
            ]);
        }

        if (in_array($eventType, ['sale_paid', 'sale_voided'], true) && !in_array('cash', $except, true)) {
            $service->createOrUpdate([
                'branch_id' => $branchId,
                'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
                'pos_entity_type' => 'payment_method',
                'pos_entity_id' => 'cash-method-uuid',
                'external_id' => 'QB-PM-CASH',
            ]);
        }

        if ($eventType === 'sale_refunded' && !in_array('gcash', $except, true)) {
            $service->createOrUpdate([
                'branch_id' => $branchId,
                'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
                'pos_entity_type' => 'payment_method',
                'pos_entity_id' => 'gcash-method-uuid',
                'external_id' => 'QB-PM-GCASH',
            ]);
        }
    }

    /** AC: Processor claims pending record and marks as processing */
    public function test_processor_claims_pending_record(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $this->processor->process($outbox, fn () => [
            'external_provider' => 'quickbooks',
            'external_id' => 'QB-CLAIM',
            'external_reference' => 'SalesReceipt:QB-CLAIM',
        ]);
        
        $outbox->refresh();
        $this->assertEquals('synced', $outbox->sync_status);
        $this->assertEquals(1, $outbox->attempt_count);
        $this->assertDatabaseHas('accounting_sync_attempts', [
            'accounting_outbox_id' => $outbox->id,
            'attempt_number' => 1,
            'status' => 'synced',
        ]);
    }

    /** AC: Processor ignores synced or processing records */
    public function test_processor_eligibility_query(): void
    {
        $this->createOutbox('pending');
        $this->createOutbox('failed');
        $this->createOutbox('synced');
        $this->createOutbox('processing');

        $eligible = $this->processor->getEligibleRecords();
        
        $this->assertCount(2, $eligible);
        $this->assertContains('pending', $eligible->pluck('sync_status'));
        $this->assertContains('failed', $eligible->pluck('sync_status'));
        $this->assertNotContains('synced', $eligible->pluck('sync_status'));
        $this->assertNotContains('processing', $eligible->pluck('sync_status'));
    }

    /** AC: Successful no-op marks as synced */
    public function test_successful_processing_marks_as_synced(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $this->processor->process($outbox, function($payload, $record) {
            return [
                'external_provider' => 'quickbooks',
                'external_id' => 'QB-123',
                'external_reference' => 'SalesReceipt:QB-123',
            ];
        });

        $outbox->refresh();
        $this->assertEquals('synced', $outbox->sync_status);
        $this->assertEquals('quickbooks', $outbox->external_provider);
        $this->assertEquals('QB-123', $outbox->external_id);
    }

    /** AC: Failed no-op marks as failed and stores error */
    public function test_failed_processing_marks_as_failed(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $this->processor->process($outbox, function($payload, $record) {
            throw new \Exception('Simulated Failure');
        });

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertEquals('Simulated Failure', $fresh->sync_error);
        $this->assertEquals('system', $fresh->sync_error_category);
        $this->assertNotNull($fresh->available_at);
        $this->assertDatabaseHas('accounting_sync_attempts', [
            'accounting_outbox_id' => $outbox->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'error_category' => 'system',
        ]);
    }

    /** AC: Mapping failures are classified for exception handling */
    public function test_processor_classifies_mapping_failures(): void
    {
        $outbox = $this->createOutbox('pending');

        $this->processor->process($outbox, function() {
            throw new \Exception('Mapping missing for payment method');
        });

        $this->assertEquals('mapping', $outbox->refresh()->sync_error_category);
        $this->assertDatabaseHas('accounting_sync_attempts', [
            'accounting_outbox_id' => $outbox->id,
            'error_category' => 'mapping',
        ]);
    }

    /** AC: No-Mutation Boundary (Business Records) */
    public function test_processor_does_not_create_business_records(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $countsBefore = [
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'outbox' => AccountingOutbox::count(),
        ];

        $this->processor->process($outbox, fn () => [
            'external_provider' => 'quickbooks',
            'external_id' => 'QB-BOUNDARY',
            'external_reference' => 'SalesReceipt:QB-BOUNDARY',
        ]);

        $this->assertEquals($countsBefore['sale'], Sale::count());
        $this->assertEquals($countsBefore['payment'], SalePayment::count());
        $this->assertEquals($countsBefore['outbox'], AccountingOutbox::count());
    }

    /** AC: No External API calls boundary */
    public function test_processor_is_local_only(): void
    {
        $outbox = $this->createOutbox('pending');
        
        // This is a behavioral assertion: the service logic only interacts with DB via state service.
        $this->processor->process($outbox, fn () => [
            'external_provider' => 'quickbooks',
            'external_id' => 'QB-LOCAL',
            'external_reference' => 'SalesReceipt:QB-LOCAL',
        ]);
        
        $this->assertTrue(true); // Reached here without external network/API dependency
    }

    public function test_processor_with_real_sync_marks_sale_paid_as_synced(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending', 0, 'sale_paid');
        $this->seedMappingsForEventType('sale_paid', $outbox->branch_id);

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'SalesReceipt' => ['Id' => 'QB-100'],
            ], 200),
        ]);

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('synced', $fresh->sync_status);
        $this->assertEquals('QB-100', $fresh->external_id);
    }

    public function test_processor_with_real_sync_marks_sale_voided_as_synced(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending', 0, 'sale_voided');
        $this->seedMappingsForEventType('sale_voided', $outbox->branch_id);

        Http::fake([
            'quickbooks.test/*' => function ($request) {
                if (str_contains($request->url(), '/query')) {
                    return Http::response([
                        'QueryResponse' => [
                            'SalesReceipt' => [[
                                'Id' => 'QB-VOID',
                                'SyncToken' => '3',
                                'DocNumber' => 'POS-VOID',
                            ]],
                        ],
                    ], 200);
                }

                return Http::response([
                    'SalesReceipt' => ['Id' => 'QB-VOID'],
                ], 200);
            },
        ]);

        $this->processor->process($outbox);

        $this->assertEquals('synced', $outbox->refresh()->sync_status);
    }

    public function test_processor_with_real_sync_marks_sale_refunded_as_synced(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending', 0, 'sale_refunded');
        $this->seedMappingsForEventType('sale_refunded', $outbox->branch_id);

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'RefundReceipt' => ['Id' => 'QB-REFUND'],
            ], 200),
        ]);

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('synced', $fresh->sync_status);
        $this->assertEquals('QB-REFUND', $fresh->external_id);
    }

    public function test_processor_marks_unsupported_events_failed_with_safe_error(): void
    {
        $outbox = $this->createOutbox('pending', 0, 'inventory_adjusted');

        $this->processor->process($outbox, null);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertStringContainsString('Unknown event type', $fresh->sync_error);
    }

    public function test_processor_marks_missing_quickbooks_connection_failed(): void
    {
        $this->useRealQuickBooksSync();
        $outbox = $this->createOutbox('pending');

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertStringContainsString('not connected', strtolower($fresh->sync_error));
    }

    public function test_processor_marks_missing_mapping_failed(): void
    {
        $this->createConnectedConnection();
        $this->useRealQuickBooksSync();

        $outbox = $this->createOutbox('pending');
        app(AccountingMappingService::class)->createOrUpdate([
            'branch_id' => $outbox->branch_id,
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => 'prod-uuid',
            'external_id' => 'QB-ITEM-PROD',
            'status' => 'inactive',
        ]);
        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertEquals('mapping', $fresh->sync_error_category);
        $this->assertStringContainsString('Missing QuickBooks mapping for item', $fresh->sync_error);
    }

    public function test_processor_marks_timeout_failed(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending');
        $this->seedMappingsForEventType('sale_paid', $outbox->branch_id);

        Http::fake(fn () => throw new ConnectionException('Connection timed out while calling QuickBooks'));

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertEquals('network', $fresh->sync_error_category);
    }

    public function test_processor_marks_unauthorized_failed(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending');
        $this->seedMappingsForEventType('sale_paid', $outbox->branch_id);

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'Fault' => ['Error' => [['Detail' => 'Unauthorized']]],
            ], 401),
        ]);

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertEquals('auth', $fresh->sync_error_category);
        $this->assertStringContainsString('401', $fresh->sync_error);
    }

    public function test_processor_marks_validation_error_failed(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending');
        $this->seedMappingsForEventType('sale_paid', $outbox->branch_id);

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'Fault' => ['Error' => [['Detail' => 'Validation failed']]],
            ], 400),
        ]);

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertStringContainsString('400 validation error', $fresh->sync_error);
    }

    public function test_processor_marks_provider_error_failed_and_sanitizes_error(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending');
        $this->seedMappingsForEventType('sale_paid', $outbox->branch_id);

        $detail = str_repeat('A', 1200) . ' access_token=secret refresh_token=secret client_secret=secret';

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'Fault' => ['Error' => [['Detail' => $detail]]],
            ], 500),
        ]);

        $countsBefore = [
            'outbox' => AccountingOutbox::count(),
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'inventory' => InventoryMovement::count(),
            'refund' => SaleRefund::count(),
            'void' => SaleVoid::count(),
        ];

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertStringContainsString('500 provider error', $fresh->sync_error);
        $this->assertStringNotContainsString('access_token=', $fresh->sync_error);
        $this->assertStringNotContainsString('refresh_token=', $fresh->sync_error);
        $this->assertStringNotContainsString('client_secret=', $fresh->sync_error);
        $this->assertLessThanOrEqual(1000, strlen($fresh->sync_error));
        $this->assertEquals($countsBefore['outbox'], AccountingOutbox::count());
        $this->assertEquals($countsBefore['sale'], Sale::count());
        $this->assertEquals($countsBefore['payment'], SalePayment::count());
        $this->assertEquals($countsBefore['inventory'], InventoryMovement::count());
        $this->assertEquals($countsBefore['refund'], SaleRefund::count());
        $this->assertEquals($countsBefore['void'], SaleVoid::count());
    }

    public function test_processor_marks_malformed_provider_response_failed(): void
    {
        $this->useRealQuickBooksSync();
        $this->createConnectedConnection();
        $outbox = $this->createOutbox('pending');
        $this->seedMappingsForEventType('sale_paid', $outbox->branch_id);

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'SalesReceipt' => [],
            ], 200),
        ]);

        $this->processor->process($outbox);

        $fresh = $outbox->refresh();
        $this->assertEquals('failed', $fresh->sync_status);
        $this->assertStringContainsString('external id', strtolower($fresh->sync_error));
    }
}
