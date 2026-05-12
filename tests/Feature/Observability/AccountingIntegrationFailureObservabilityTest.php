<?php

namespace Tests\Feature\Observability;

use App\Jobs\ProcessAccountingOutboxJob;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\Accounting\QuickBooksSyncService;
use App\Services\BranchContext;
use App\Services\Observability\RequestCorrelation;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AccountingIntegrationFailureObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_quickbooks_connection_failure_logs_safe_structured_context(): void
    {
        Log::spy();
        Http::fake();

        $outbox = $this->createOutbox();
        app(RequestCorrelation::class)->set('failure-correlation-123');

        QuickBooksConnection::withoutGlobalScope('tenant')
            ->where('tenant_id', $outbox->tenant_id)
            ->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('QuickBooks is not connected for this tenant.');

        try {
            app(QuickBooksSyncService::class)->sync($outbox);
        } finally {
            Log::shouldHaveReceived('warning')->atLeast()->once()->with(
                'accounting.quickbooks.connection.failed',
                Mockery::on(fn (array $context) => $this->assertSafeFailureContext($context, $outbox, [
                    'correlation_id' => 'failure-correlation-123',
                    'provider' => 'quickbooks',
                    'error_category' => 'auth',
                    'operation' => 'create',
                    'entity' => 'SalesReceipt',
                ]))
            );

            Http::assertNothingSent();
            $this->assertSame(0, \DB::table('sales')->count());
        }
    }

    public function test_quickbooks_sync_failure_and_retry_scheduling_logs_are_sanitized(): void
    {
        $warnings = [];

        Log::partialMock()
            ->shouldReceive('warning')
            ->andReturnUsing(function (string $event, array $context) use (&$warnings) {
                $warnings[] = [$event, $context];
            });

        $outbox = $this->createOutbox();
        app(RequestCorrelation::class)->set('retry-correlation-123');

        Http::fake([
            'quickbooks.test/*' => Http::response([
                'Fault' => [
                    'Error' => [[
                        'Detail' => 'Authorization: Bearer secret-token access_token=secret-value provider payload should stay hidden',
                    ]],
                ],
            ], 429),
        ]);

        app(AccountingOutboxProcessorService::class)->process($outbox);
        $outbox->refresh();

        $this->assertSame('failed', $outbox->sync_status);

        $syncFailure = collect($warnings)->first(fn (array $entry) => $entry[0] === 'accounting.quickbooks.sync.failed');
        $retryScheduled = collect($warnings)->first(fn (array $entry) => $entry[0] === 'accounting.outbox.retry_scheduled');

        $this->assertNotNull($syncFailure, 'Expected sanitized accounting.quickbooks.sync.failed warning was not emitted. Captured warnings: ' . json_encode($warnings, JSON_THROW_ON_ERROR));
        $this->assertNotNull($retryScheduled, 'Expected sanitized accounting.outbox.retry_scheduled warning was not emitted. Captured warnings: ' . json_encode($warnings, JSON_THROW_ON_ERROR));

        $this->assertTrue($this->assertSafeFailureContext($syncFailure[1], $outbox, [
            'correlation_id' => 'retry-correlation-123',
            'provider' => 'quickbooks',
            'operation' => 'create',
            'entity' => 'SalesReceipt',
        ]));
        $this->assertContains($syncFailure[1]['error_category'] ?? null, ['auth', 'rate_limit', 'validation', 'provider', 'system']);

        $this->assertTrue($this->assertSafeFailureContext($retryScheduled[1], $outbox, [
            'correlation_id' => 'retry-correlation-123',
            'attempt_count' => 1,
            'error_category' => $syncFailure[1]['error_category'] ?? null,
        ]));
        $this->assertTrue(filled($retryScheduled[1]['next_attempt_at'] ?? null));

        Http::assertSentCount(1);
        $this->assertSame(0, \DB::table('accounting_outbox')->where('sync_status', 'synced')->count());
        $this->assertSame(0, \DB::table('sales')->count());
    }

    public function test_unexpected_accounting_job_exception_emits_safe_baseline_log(): void
    {
        Log::spy();

        $job = new ProcessAccountingOutboxJob((string) Str::uuid(), 'job-failure-correlation');

        try {
            $job->handle(
                app(AccountingOutboxProcessorService::class),
                app(TenantContext::class),
                app(BranchContext::class),
                app(RequestCorrelation::class)
            );

            $this->fail('Expected missing outbox record exception was not thrown.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('No query results for model', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->atLeast()->once()->with(
            'accounting.outbox_job.exception',
            Mockery::on(function (array $context) use ($job) {
                return ($context['correlation_id'] ?? null) === 'job-failure-correlation'
                    && ($context['job_class'] ?? null) === ProcessAccountingOutboxJob::class
                    && ($context['queue'] ?? null) === 'accounting'
                    && ($context['outbox_id'] ?? null) === $job->outboxId
                    && ($context['error_category'] ?? null) === 'system'
                    && ($context['exception_class'] ?? null) === \Illuminate\Database\Eloquent\ModelNotFoundException::class
                    && !array_key_exists('Authorization', $context)
                    && !array_key_exists('authorization', $context)
                    && !array_key_exists('provider_payload', $context)
                    && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Bearer');
            })
        );
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

        QuickBooksConnection::create([
            'tenant_id' => $tenant->id,
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        $this->seedMappings($branch->id);

        return AccountingOutbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => [
                'sale_number' => 'POS-FAIL-123',
                'subtotal' => '100.0000',
                'tax_total' => '0.0000',
                'total' => '100.0000',
                'Authorization' => 'Bearer secret-token',
                'provider_payload' => ['secret' => 'hidden'],
                'items' => [[
                    'product_id' => 'prod-uuid',
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
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => 'prod-uuid',
            'external_id' => 'QB-ITEM-PROD',
        ]);

        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => 'cash-method-uuid',
            'external_id' => 'QB-PM-CASH',
        ]);
    }

    protected function assertSafeFailureContext(array $context, AccountingOutbox $outbox, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                return false;
            }
        }

        if (($context['outbox_id'] ?? null) !== $outbox->id
            || ($context['tenant_id'] ?? null) !== $outbox->tenant_id
            || ($context['branch_id'] ?? null) !== $outbox->branch_id
            || array_key_exists('Authorization', $context)
            || array_key_exists('authorization', $context)
            || array_key_exists('headers', $context)
            || array_key_exists('cookies', $context)
            || array_key_exists('session', $context)
            || array_key_exists('query', $context)
            || array_key_exists('request', $context)
            || array_key_exists('raw_request_body', $context)
            || array_key_exists('payload', $context)
            || array_key_exists('provider_payload', $context)
            || array_key_exists('provider_token', $context)) {
            return false;
        }

        $serialized = json_encode($context, JSON_THROW_ON_ERROR);

        return !str_contains($serialized, 'Bearer')
            && !str_contains($serialized, 'access-token')
            && !str_contains($serialized, 'refresh-token')
            && !str_contains($serialized, 'client_secret')
            && !str_contains($serialized, 'secret-token')
            && !str_contains($serialized, 'provider payload');
    }
}