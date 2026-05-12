<?php

namespace Tests\Feature\Observability;

use App\Models\Branch;
use App\Models\SupportAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Support\SupportPayloadMasker;
use App\Services\SupportAccessSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SupportObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_assisted_request_logs_include_safe_normalized_context(): void
    {
        Http::fake();
        Log::spy();

        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = $this->createBranch($tenant);
        $supportUser = User::factory()->platformSupport()->create(['status' => 'active']);

        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Observability probe.'
        );

        $this->actingAs($supportUser)
            ->withHeader('X-Correlation-ID', 'support-correlation-123')
            ->withSession(['support_secret_session' => 'support-session-secret'])
            ->withCookie('support_sensitive_cookie', 'support-cookie-secret')
            ->getJson(route('support.assisted.probe', $session))
            ->assertOk();

        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'support.assisted.request.accessed',
            Mockery::on(function (array $context) use ($tenant, $branch, $supportUser, $session) {
                if (($context['correlation_id'] ?? null) !== 'support-correlation-123'
                    || ($context['tenant_id'] ?? null) !== $tenant->id
                    || ($context['branch_id'] ?? null) !== $branch->id
                    || ($context['actor_id'] ?? null) !== $supportUser->id
                    || ($context['actor_type'] ?? null) !== $supportUser->actor_type
                    || ($context['route_name'] ?? null) !== 'support.assisted.probe'
                    || ($context['support_session_id'] ?? null) !== $session->id
                    || array_key_exists('Authorization', $context)
                    || array_key_exists('authorization', $context)
                    || array_key_exists('headers', $context)
                    || array_key_exists('cookies', $context)
                    || array_key_exists('session', $context)
                    || array_key_exists('query', $context)
                    || array_key_exists('request', $context)
                    || array_key_exists('raw_request_body', $context)
                    || array_key_exists('provider_payload', $context)
                    || array_key_exists('provider_token', $context)) {
                    return false;
                }

                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                if (str_contains($serialized, 'Bearer') || str_contains($serialized, 'secret')) {
                    return false;
                }

                return true;
            })
        );

        $routeEvent = SupportAuditEvent::where('event_type', 'support.route.accessed')->latest()->firstOrFail();

        $this->assertSame('support.assisted.probe', $routeEvent->route_name);
        $this->assertSame($session->id, $routeEvent->support_session_id);
        $this->assertSame(SupportPayloadMasker::REDACTED, $routeEvent->metadata['response']['access_token']);
        $this->assertSame(SupportPayloadMasker::REDACTED_PAYLOAD, $routeEvent->metadata['response']['metadata']['provider_payload']);
        $this->assertSame($tenant->id, $routeEvent->metadata['response']['tenant_id']);

        $auditSerialized = json_encode($routeEvent->metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('support-session-secret', $auditSerialized);
        $this->assertStringNotContainsString('support-cookie-secret', $auditSerialized);

        $this->assertSame(0, \DB::table('accounting_outbox')->count());
        Http::assertNothingSent();
    }

    public function test_support_assisted_validation_failures_log_safe_context_without_payload_leakage(): void
    {
        Http::fake();
        Log::spy();

        $supportUser = User::factory()->platformSupport()->create(['status' => 'active']);
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Expire then fail.'
        );

        $session->forceFill([
            'status' => 'expired',
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($supportUser)
            ->withHeader('X-Correlation-ID', 'support-failure-correlation')
            ->getJson(route('support.assisted.probe', $session))
            ->assertStatus(403);

        Log::shouldHaveReceived('warning')->atLeast()->once()->with(
            'support.assisted.session.validation_failed',
            Mockery::on(function (array $context) use ($supportUser, $session) {
                if (($context['correlation_id'] ?? null) !== 'support-failure-correlation'
                    || ($context['actor_id'] ?? null) !== $supportUser->id
                    || ($context['actor_type'] ?? null) !== $supportUser->actor_type
                    || ($context['route_name'] ?? null) !== 'support.assisted.probe'
                    || ($context['support_session_id'] ?? null) !== $session->id
                    || array_key_exists('Authorization', $context)
                    || array_key_exists('authorization', $context)
                    || array_key_exists('headers', $context)
                    || array_key_exists('cookies', $context)
                    || array_key_exists('session', $context)
                    || array_key_exists('query', $context)
                    || array_key_exists('request', $context)
                    || array_key_exists('raw_request_body', $context)
                    || array_key_exists('provider_payload', $context)) {
                    return false;
                }

                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                if (str_contains($serialized, 'Bearer') || str_contains($serialized, 'secret')) {
                    return false;
                }

                return true;
            })
        );

        $this->assertSame(0, \DB::table('accounting_outbox')->count());
        Http::assertNothingSent();
    }

    protected function createBranch(Tenant $tenant): Branch
    {
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        app(\App\Services\TenantContext::class)->clear();

        return $branch;
    }
}