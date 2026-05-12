<?php

namespace Tests\Feature\Accounting;

use App\Models\QuickBooksConnection;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\QuickBooksConnectionService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class QuickBooksConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost:8000/accounting/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
        ]);

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->first());
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_connect_redirects_to_intuit_and_stores_oauth_state(): void
    {
        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.connect'));

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('scope=com.intuit.quickbooks.accounting', $location);
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A8000%2Faccounting%2Fquickbooks%2Fcallback', $location);
        $this->assertNotNull(session('quickbooks_oauth_state'));
        $this->assertEquals($this->tenant->id, session('quickbooks_oauth_tenant_id'));
    }

    public function test_callback_stores_encrypted_tokens_and_audits_connection(): void
    {
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token' => 'plain-access-token',
                'refresh_token' => 'plain-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 86400,
                'token_type' => 'bearer',
            ]),
        ]);

        $state = 'state-123';

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withSession([
                'quickbooks_oauth_state' => $state,
                'quickbooks_oauth_tenant_id' => $this->tenant->id,
            ])
            ->get(route('accounting.quickbooks.callback', [
                'code' => 'auth-code',
                'realmId' => 'realm-123',
                'state' => $state,
            ]));

        $response->assertRedirect('/dashboard');

        $connection = QuickBooksConnection::first();
        $this->assertEquals(QuickBooksConnection::STATUS_CONNECTED, $connection->status);
        $this->assertEquals('realm-123', $connection->realm_id);
        $this->assertEquals('plain-access-token', $connection->access_token);
        $this->assertEquals('plain-refresh-token', $connection->refresh_token);
        $this->assertTrue($connection->access_token_expires_at->isFuture());

        $raw = DB::table('quickbooks_connections')->where('id', $connection->id)->first();
        $this->assertNotEquals('plain-access-token', $raw->access_token);
        $this->assertNotEquals('plain-refresh-token', $raw->refresh_token);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'quickbooks_connected',
            'auditable_id' => $connection->id,
        ]);
    }

    public function test_status_and_queries_are_tenant_isolated(): void
    {
        QuickBooksConnection::create([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'tenant-a',
            'access_token' => 'token-a',
            'refresh_token' => 'refresh-a',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantB);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);
        app(TenantContext::class)->setTenant($tenantB);
        $userB->assignRole(Role::where('name', 'Accountant')->first());

        $this->assertCount(0, QuickBooksConnection::all());

        $response = $this->actingAs($userB)
            ->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson(route('accounting.quickbooks.status'));

        $response->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('status', QuickBooksConnection::STATUS_DISCONNECTED);
    }

    public function test_guard_blocks_missing_disconnected_and_expired_connections(): void
    {
        $service = app(QuickBooksConnectionService::class);

        try {
            $service->assertConnectedForTenant();
            $this->fail('Missing QuickBooks connection was allowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not connected', $exception->getMessage());
        }

        $connection = QuickBooksConnection::create([
            'status' => QuickBooksConnection::STATUS_DISCONNECTED,
        ]);

        try {
            $service->assertConnectedForTenant();
            $this->fail('Disconnected QuickBooks connection was allowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not connected', $exception->getMessage());
        }

        $connection->update([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        try {
            $service->assertConnectedForTenant();
            $this->fail('Expired access token was allowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('access token has expired', $exception->getMessage());
        }

        $this->assertEquals(QuickBooksConnection::STATUS_EXPIRED, $connection->refresh()->status);
    }

    public function test_guard_allows_active_connected_connection(): void
    {
        QuickBooksConnection::create([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        $connection = app(QuickBooksConnectionService::class)->assertConnectedForTenant();

        $this->assertEquals('realm-123', $connection->realm_id);
    }

    public function test_disconnect_clears_tokens_and_marks_connection_disconnected(): void
    {
        $connection = QuickBooksConnection::create([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson(route('accounting.quickbooks.disconnect'), ['reason' => 'manual disconnect']);

        $response->assertOk()
            ->assertJsonPath('status', QuickBooksConnection::STATUS_DISCONNECTED)
            ->assertJsonPath('connected', false);

        $connection->refresh();
        $this->assertNull($connection->access_token);
        $this->assertNull($connection->refresh_token);
        $this->assertNotNull($connection->disconnected_at);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'quickbooks_disconnected',
            'auditable_id' => $connection->id,
        ]);
    }
}
