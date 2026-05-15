<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\SupportAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\SupportAccessSessionService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSecretExposureTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $accountant;
    protected User $supportUser;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost/accounting/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.authorization_url' => 'https://appcenter.intuit.com/connect/oauth2',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
            'services.quickbooks.api_base_url' => 'https://sandbox-quickbooks.api.intuit.test',
        ]);

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'email' => 'accountant-secret-review@example.test',
        ]);
        $this->accountant->assignRole(\App\Models\Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branch);

        $this->supportUser = User::factory()->platformSupport()->create([
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_support_audit_review_response_redacts_string_embedded_secrets_and_config_fragments(): void
    {
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $this->supportUser,
            tenant: $this->tenant,
            branch: $this->branch,
            reason: 'Secret exposure audit review.'
        );

        SupportAuditEvent::create([
            'event_type' => 'support.route.accessed',
            'support_session_id' => $session->id,
            'actor_id' => $this->supportUser->id,
            'route_name' => 'support.assisted.audit-events.index',
            'path' => '/support/assisted/'.$session->id.'/audit-events',
            'method' => 'GET',
            'status' => 'allowed',
            'metadata' => [
                'message' => 'Authorization: Bearer super-secret refresh_token=refresh-secret access_token=access-secret client_secret=client-secret',
                'config_dump' => 'APP_KEY=base64:unsafe-secret DB_PASSWORD=db-secret MAIL_PASSWORD=mail-secret',
                'provider_payload' => [
                    'secret' => 'payload-secret',
                ],
            ],
        ]);

        $response = $this->actingAs($this->supportUser)
            ->getJson(route('support.assisted.audit-events.index', $session));

        $response->assertOk();

        $reviewedEvent = collect($response->json('data'))->firstWhere('event_type', 'support.route.accessed');

        $this->assertNotNull($reviewedEvent);
        $this->assertSame('[REDACTED]', $reviewedEvent['metadata']['message'] ?? null);
        $this->assertSame('[REDACTED]', $reviewedEvent['metadata']['config_dump'] ?? null);
        $this->assertSame('[REDACTED_PAYLOAD]', $reviewedEvent['metadata']['provider_payload'] ?? null);

        $serialized = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('super-secret', $serialized);
        $this->assertStringNotContainsString('refresh-secret', $serialized);
        $this->assertStringNotContainsString('access-secret', $serialized);
        $this->assertStringNotContainsString('client-secret', $serialized);
        $this->assertStringNotContainsString('unsafe-secret', $serialized);
        $this->assertStringNotContainsString('db-secret', $serialized);
        $this->assertStringNotContainsString('mail-secret', $serialized);
        $this->assertStringNotContainsString('payload-secret', $serialized);
        $this->assertStringNotContainsString('Authorization', $serialized);
        $this->assertStringNotContainsString('Bearer', $serialized);
        $this->assertStringNotContainsString('APP_KEY', $serialized);
        $this->assertStringNotContainsString('DB_PASSWORD', $serialized);
    }

    public function test_quickbooks_callback_error_sanitizes_provider_and_environment_secret_fragments(): void
    {
        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.callback', [
                'error' => 'access_denied',
                'error_description' => 'Authorization: Bearer callback-secret refresh_token=refresh-secret APP_KEY=base64:unsafe-secret DB_PASSWORD=db-secret provider payload should stay hidden',
            ]));

        $response->assertRedirect(route('accounting.quickbooks.index'));

        $error = session('error');

        $this->assertNotNull($error);
        $this->assertStringNotContainsString('callback-secret', $error);
        $this->assertStringNotContainsString('refresh-secret', $error);
        $this->assertStringNotContainsString('unsafe-secret', $error);
        $this->assertStringNotContainsString('db-secret', $error);
        $this->assertStringNotContainsString('Bearer', $error);
        $this->assertStringContainsString('Authorization: [redacted]', $error);
        $this->assertStringContainsString('refresh_token=[redacted]', $error);
        $this->assertStringContainsString('APP_KEY=[redacted]', $error);
        $this->assertStringContainsString('DB_PASSWORD=[redacted]', $error);
    }
}