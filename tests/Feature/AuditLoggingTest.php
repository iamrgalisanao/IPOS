<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_it_can_record_audit_logs_with_tenant_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $logger = app(AuditLogger::class);
        $log = $logger->log(
            action: 'TEST_ACTION',
            beforeValues: ['old' => 'value'],
            afterValues: ['new' => 'value'],
            reason: 'Testing purpose',
            remarks: 'Some remarks'
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'tenant_id' => $tenant->id,
            'action' => 'TEST_ACTION',
            'reason' => 'Testing purpose',
        ]);

        $this->assertEquals(['old' => 'value'], $log->before_values);
        $this->assertEquals(['new' => 'value'], $log->after_values);
        $this->assertNotNull($log->created_at);
    }

    /** @test */
    public function test_it_fails_loudly_if_logging_without_tenant_context(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is required');

        app(AuditLogger::class)->log('ANY_ACTION');
    }

    /** @test */
    public function test_audit_logs_are_immutable_and_cannot_be_updated(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $log = app(AuditLogger::class)->log('IMMUTABLE_TEST');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit logs are append-only and cannot be updated.');

        $log->update(['action' => 'CHANGED']);
    }

    /** @test */
    public function test_audit_logs_are_immutable_and_cannot_be_deleted(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $log = app(AuditLogger::class)->log('IMMUTABLE_TEST');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit logs are append-only and cannot be deleted.');

        $log->delete();
    }

    /** @test */
    public function test_audit_logs_are_properly_scoped_to_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Log for Tenant A
        app(TenantContext::class)->setTenant($tenantA);
        app(AuditLogger::class)->log('ACTION_A');
        app(TenantContext::class)->clear();

        // Log for Tenant B
        app(TenantContext::class)->setTenant($tenantB);
        app(AuditLogger::class)->log('ACTION_B');
        app(TenantContext::class)->clear();

        // Verify isolation
        app(TenantContext::class)->setTenant($tenantA);
        $this->assertEquals(1, AuditLog::count());
        $this->assertEquals('ACTION_A', AuditLog::first()->action);
    }
}
