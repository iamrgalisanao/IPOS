<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\AccountingSyncAttempt;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\QuickBooksSyncService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingOutboxOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These command-level orchestration tests verify tenant hydration and backoff selection,
        // not provider integration details. The provider is faked to preserve that boundary.
        app()->instance(QuickBooksSyncService::class, new class extends QuickBooksSyncService {
            public function __construct() {}

            public function sync(AccountingOutbox $record): array
            {
                return [
                    'external_provider' => 'quickbooks',
                    'external_id' => 'QB-CMD-' . substr((string) $record->id, 0, 8),
                    'external_reference' => 'SalesReceipt:QB-CMD',
                ];
            }
        });
    }

    public function test_process_outbox_command_hydrates_tenant_context_per_record(): void
    {
        $recordA = $this->createOutboxForTenant('POS-A');
        $recordB = $this->createOutboxForTenant('POS-B');

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->artisan('accounting:process-outbox', ['--sync' => true])
            ->expectsOutput('Queued 2 accounting outbox record(s).')
            ->assertSuccessful();

        $this->assertEquals('synced', $recordA->refresh()->sync_status);
        $this->assertEquals('synced', $recordB->refresh()->sync_status);
        $this->assertEquals(2, AccountingSyncAttempt::withoutGlobalScope('tenant')->count());
    }

    public function test_process_outbox_command_respects_available_at_backoff(): void
    {
        $ready = $this->createOutboxForTenant('POS-READY');
        $deferred = $this->createOutboxForTenant('POS-DEFERRED', [
            'sync_status' => 'failed',
            'available_at' => now()->addHour(),
            'next_attempt_at' => now()->addHour(),
        ]);

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->artisan('accounting:process-outbox', ['--sync' => true])
            ->expectsOutput('Queued 1 accounting outbox record(s).')
            ->assertSuccessful();

        $this->assertEquals('synced', $ready->refresh()->sync_status);
        $this->assertEquals('failed', $deferred->refresh()->sync_status);
        $this->assertEquals(1, AccountingSyncAttempt::withoutGlobalScope('tenant')->count());
    }

    protected function createOutboxForTenant(string $saleNumber, array $overrides = []): AccountingOutbox
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($branch);
        $this->seedMappings($branch->id);

        return AccountingOutbox::create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => [
                'sale_number' => $saleNumber,
                'subtotal' => '100.0000',
                'tax_total' => '0.0000',
                'total' => '100.0000',
                'items' => [[
                    'product_id' => null,
                    'product_name' => 'Test Item',
                    'quantity' => '1.0000',
                    'unit_price' => '100.0000',
                    'line_total' => '100.0000',
                ]],
                'payments' => [[
                    'method' => 'cash-method-uuid',
                    'amount' => '100.0000',
                ]],
            ],
            'sync_status' => 'pending',
            'available_at' => now(),
        ], $overrides));
    }

    protected function seedMappings(string $branchId): void
    {
        $service = app(AccountingMappingService::class);

        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => 'cash-method-uuid',
            'external_id' => 'QB-PM-CASH',
        ]);
    }
}
