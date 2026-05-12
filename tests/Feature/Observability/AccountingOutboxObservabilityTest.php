<?php

namespace Tests\Feature\Observability;

use App\Jobs\ProcessAccountingOutboxJob;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\Accounting\QuickBooksSyncService;
use App\Services\BranchContext;
use App\Services\Observability\RequestCorrelation;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AccountingOutboxObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_uses_provided_or_generated_safe_correlation_ids(): void
    {
        $requestCorrelation = app(RequestCorrelation::class);

        $provided = new ProcessAccountingOutboxJob('outbox-1', 'trace-123:abc_DEF.1');
        $this->assertSame('trace-123:abc_DEF.1', $provided->correlationId);

        $requestCorrelation->set('request-correlation-123');
        $inherited = new ProcessAccountingOutboxJob('outbox-2');
        $this->assertSame('request-correlation-123', $inherited->correlationId);

        $requestCorrelation->clear();

        $invalid = new ProcessAccountingOutboxJob('outbox-3', 'bad value with spaces');
        $this->assertTrue(Str::isUuid($invalid->correlationId));

        $generated = new ProcessAccountingOutboxJob('outbox-4');
        $this->assertTrue(Str::isUuid($generated->correlationId));
    }

    public function test_job_restores_correlation_context_and_logs_safe_queue_metadata(): void
    {
        Log::spy();

        $outbox = $this->createOutbox();
        $requestCorrelation = app(RequestCorrelation::class);
        $requestCorrelation->set('request-trace-123');

        $quickBooks = new class extends QuickBooksSyncService {
            public ?string $seenCorrelationId = null;

            public function __construct() {}

            public function sync(AccountingOutbox $record): array
            {
                $this->seenCorrelationId = app(RequestCorrelation::class)->current();

                return [
                    'external_provider' => 'quickbooks',
                    'external_id' => 'QB-OBS-123',
                    'external_reference' => 'SalesReceipt:QB-OBS-123',
                ];
            }
        };

        app()->instance(QuickBooksSyncService::class, $quickBooks);

        $job = new ProcessAccountingOutboxJob($outbox->id);

        $this->assertSame('request-trace-123', $job->correlationId);

        $requestCorrelation->clear();

        $job->handle(
            app(AccountingOutboxProcessorService::class),
            app(TenantContext::class),
            app(BranchContext::class),
            app(RequestCorrelation::class)
        );

        $outbox->refresh();

        $this->assertSame('synced', $outbox->sync_status);
        $this->assertSame('request-trace-123', $quickBooks->seenCorrelationId);
        $this->assertNull(app(RequestCorrelation::class)->current());
        $this->assertNull(app(TenantContext::class)->getTenantId());
        $this->assertNull(app(BranchContext::class)->getBranchId());

        Log::shouldHaveReceived('shareContext')
            ->atLeast()
            ->once()
            ->with(Mockery::on(function (array $context) use ($job, $outbox) {
                return $context === [
                    'correlation_id' => 'request-trace-123',
                    'job_class' => ProcessAccountingOutboxJob::class,
                    'queue' => 'accounting',
                    'outbox_id' => $outbox->id,
                    'tenant_id' => $outbox->tenant_id,
                    'branch_id' => $outbox->branch_id,
                    'attempt_count' => 1,
                ];
            }));

        Log::shouldHaveReceived('info')
            ->atLeast()
            ->once()
            ->with('accounting.outbox_job.started', Mockery::on(fn (array $context) => $this->assertSafeQueueContext($context, $outbox, 'request-trace-123')));

        Log::shouldHaveReceived('info')
            ->atLeast()
            ->once()
            ->with('accounting.outbox_job.completed', Mockery::on(fn (array $context) => $this->assertSafeQueueContext($context, $outbox, 'request-trace-123')));

        Log::shouldHaveReceived('withoutContext')
            ->once()
            ->with([
                'correlation_id',
                'job_class',
                'queue',
                'outbox_id',
                'tenant_id',
                'branch_id',
                'attempt_count',
            ]);
    }

    public function test_job_replaces_invalid_queue_correlation_and_logs_failure_without_payload_leakage(): void
    {
        Log::spy();

        $outbox = $this->createOutbox();

        app()->instance(QuickBooksSyncService::class, new class extends QuickBooksSyncService {
            public function __construct() {}

            public function sync(AccountingOutbox $record): array
            {
                throw new \RuntimeException('Provider token secret-should-not-leak');
            }
        });

        $job = new ProcessAccountingOutboxJob($outbox->id, 'bad value with spaces');

        $this->assertTrue(Str::isUuid($job->correlationId));

        $job->handle(
            app(AccountingOutboxProcessorService::class),
            app(TenantContext::class),
            app(BranchContext::class),
            app(RequestCorrelation::class)
        );

        $outbox->refresh();

        $this->assertSame('failed', $outbox->sync_status);
        $this->assertNull(app(RequestCorrelation::class)->current());

        Log::shouldHaveReceived('warning')
            ->atLeast()
            ->once()
            ->with('accounting.outbox_job.failed', Mockery::on(function (array $context) use ($outbox, $job) {
                return $this->assertSafeQueueContext($context, $outbox, $job->correlationId);
            }));
    }

    protected function createOutbox(): AccountingOutbox
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($branch);

        $this->seedMappings($branch->id);

        return AccountingOutbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => [
                'sale_number' => 'POS-OBS-123',
                'subtotal' => '100.0000',
                'tax_total' => '0.0000',
                'total' => '100.0000',
                'Authorization' => 'Bearer secret-token',
                'provider_token' => 'secret-should-not-leak',
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
        ]);
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

    protected function assertSafeQueueContext(array $context, AccountingOutbox $outbox, string $correlationId): bool
    {
        $this->assertSame($correlationId, $context['correlation_id'] ?? null);
        $this->assertSame(ProcessAccountingOutboxJob::class, $context['job_class'] ?? null);
        $this->assertSame('accounting', $context['queue'] ?? null);
        $this->assertSame($outbox->id, $context['outbox_id'] ?? null);
        $this->assertSame($outbox->tenant_id, $context['tenant_id'] ?? null);
        $this->assertSame($outbox->branch_id, $context['branch_id'] ?? null);
        $this->assertSame(1, $context['attempt_count'] ?? null);
        $this->assertArrayNotHasKey('Authorization', $context);
        $this->assertArrayNotHasKey('authorization', $context);
        $this->assertArrayNotHasKey('payload', $context);
        $this->assertArrayNotHasKey('provider_payload', $context);
        $this->assertArrayNotHasKey('provider_token', $context);
        $this->assertStringNotContainsString('Bearer', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-should-not-leak', json_encode($context, JSON_THROW_ON_ERROR));

        return true;
    }
}