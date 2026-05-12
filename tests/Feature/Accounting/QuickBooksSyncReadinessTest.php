<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\QuickBooksSyncReadinessService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickBooksSyncReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(BranchContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_ready_record_reports_pass_and_payload_preview(): void
    {
        $this->createConnection();
        $record = $this->createOutbox();

        $result = app(QuickBooksSyncReadinessService::class)->analyze($record);

        $this->assertTrue($result['ready']);
        $this->assertSame('pass', $result['connection']['status']);
        $this->assertSame('pass', $result['payload']['status']);
        $this->assertSame('SalesReceipt', $result['payload']['payload_preview']['entity']);
        $this->assertSame('create', $result['payload']['payload_preview']['operation']);
    }

    public function test_disconnected_tenant_reports_connection_failure_but_still_validates_payload(): void
    {
        $record = $this->createOutbox();

        $result = app(QuickBooksSyncReadinessService::class)->analyze($record);

        $this->assertFalse($result['ready']);
        $this->assertSame('fail', $result['connection']['status']);
        $this->assertStringContainsString('not connected', strtolower($result['connection']['message']));
        $this->assertSame('pass', $result['payload']['status']);
        $this->assertArrayNotHasKey('payload_preview', $result['payload']);
    }

    public function test_missing_mapping_reports_payload_failure(): void
    {
        $this->createConnection();
        $record = $this->createOutbox(seedMappings: false);

        $result = app(QuickBooksSyncReadinessService::class)->analyze($record);

        $this->assertFalse($result['ready']);
        $this->assertSame('pass', $result['connection']['status']);
        $this->assertSame('fail', $result['payload']['status']);
        $this->assertStringContainsString('missing quickbooks mapping', strtolower($result['payload']['message']));
        $this->assertArrayNotHasKey('payload_preview', $result['payload']);
    }

    public function test_expired_token_reports_connection_failure(): void
    {
        QuickBooksConnection::create([
            'tenant_id' => $this->tenant->id,
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'company_name' => 'Sandbox Co',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        $record = $this->createOutbox();

        $result = app(QuickBooksSyncReadinessService::class)->analyze($record);

        $this->assertFalse($result['ready']);
        $this->assertSame('fail', $result['connection']['status']);
        $this->assertStringContainsString('access token has expired', strtolower($result['connection']['message']));
        $this->assertArrayNotHasKey('payload_preview', $result['payload']);
    }

    public function test_unsupported_event_reports_payload_failure_without_api_calls_or_mutation(): void
    {
        $this->createConnection();
        $this->seedMappings();

        $record = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'unsupported_event',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => ['sale_number' => 'POS-UNSUPPORTED'],
            'sync_status' => 'pending',
            'attempt_count' => 0,
        ]);

        $before = $record->only(['sync_status', 'attempt_count']);

        Http::fake();
        $result = app(QuickBooksSyncReadinessService::class)->analyze($record);

        Http::assertNothingSent();
        $record->refresh();

        $this->assertFalse($result['ready']);
        $this->assertSame('fail', $result['payload']['status']);
        $this->assertStringContainsString('unknown event type', strtolower($result['payload']['message']));
        $this->assertArrayNotHasKey('payload_preview', $result['payload']);
        $this->assertSame($before['sync_status'], $record->sync_status);
        $this->assertSame($before['attempt_count'], $record->attempt_count);
        $this->assertDatabaseCount('accounting_sync_attempts', 0);
        $this->assertDatabaseCount('accounting_outbox', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('sale_refunds', 0);
        $this->assertDatabaseCount('sale_voids', 0);
    }

    public function test_payload_preview_does_not_expose_secrets_or_credentials(): void
    {
        $this->createConnection();
        $record = $this->createOutbox();

        $result = app(QuickBooksSyncReadinessService::class)->analyze($record);
        $preview = json_encode($result['payload']['payload_preview']);

        $this->assertNotFalse($preview);
        $this->assertStringNotContainsString('access_token', $preview);
        $this->assertStringNotContainsString('refresh_token', $preview);
        $this->assertStringNotContainsString('client_secret', $preview);
        $this->assertStringNotContainsString('api_key', $preview);
        $this->assertStringNotContainsString('private_key', $preview);
        $this->assertStringNotContainsString('Authorization', $preview);
        $this->assertStringNotContainsString('Bearer', $preview);
    }

    protected function createConnection(): QuickBooksConnection
    {
        return QuickBooksConnection::create([
            'tenant_id' => $this->tenant->id,
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'company_name' => 'Sandbox Co',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);
    }

    protected function createOutbox(bool $seedMappings = true): AccountingOutbox
    {
        $payload = [
            'sale_number' => 'POS-DRY-RUN',
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
                'reference' => 'REF-123',
            ]],
        ];

        $record = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => $payload,
            'sync_status' => 'pending',
        ]);

        if ($seedMappings) {
            $this->seedMappings();
        }

        return $record;
    }

    protected function seedMappings(): void
    {
        $service = app(AccountingMappingService::class);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => 'prod-uuid',
            'external_id' => 'QB-ITEM-PROD',
        ]);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => 'tax-uuid',
            'external_id' => 'QB-TAX-STANDARD',
        ]);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => 'cash-method-uuid',
            'external_id' => 'QB-PM-CASH',
        ]);
    }
}
