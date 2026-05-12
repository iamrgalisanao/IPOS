<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingMapping;
use App\Models\AccountingOutbox;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\QuickBooksConnection;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickBooksConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $accountant;

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
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branch);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_authorized_user_can_view_connection_status_page(): void
    {
        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/QuickBooks/Connection')
                ->where('connection.connected', false)
                ->where('connection.status', QuickBooksConnection::STATUS_DISCONNECTED)
                ->where('connection.environment', 'sandbox')
                ->missing('connection.access_token')
                ->missing('connection.refresh_token')
                ->missing('connection.metadata')
            );
    }

    public function test_unauthorized_user_receives_403(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $cashier->assignToBranch($this->branch);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.index'))
            ->assertForbidden();
    }

    public function test_tenant_cannot_view_another_tenants_connection(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        QuickBooksConnection::create([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'tenant-b-realm',
            'access_token' => 'tenant-b-access-token',
            'refresh_token' => 'tenant-b-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(10),
            'metadata' => ['environment' => 'production'],
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/QuickBooks/Connection')
                ->where('connection.connected', false)
                ->where('connection.realm_id', null)
                ->where('connection.environment', 'sandbox')
            );
    }

    public function test_tenant_cannot_disconnect_another_tenants_connection(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        $foreignConnection = QuickBooksConnection::create([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'tenant-b-realm',
            'access_token' => 'tenant-b-access-token',
            'refresh_token' => 'tenant-b-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(10),
            'metadata' => ['environment' => 'production'],
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.quickbooks.disconnect'), ['reason' => 'manual disconnect'])
            ->assertRedirect(route('accounting.quickbooks.index'));

        app(TenantContext::class)->setTenant($tenantB);
        $this->assertTrue($foreignConnection->refresh()->isConnected());
        app(TenantContext::class)->clear();
    }

    public function test_connect_action_generates_valid_authorization_redirect(): void
    {
        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.quickbooks.connect'));

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('scope=com.intuit.quickbooks.accounting', $location);
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%2Faccounting%2Fquickbooks%2Fcallback', $location);
        $this->assertNotNull(session('quickbooks_oauth_state'));
        $this->assertSame($this->tenant->id, session('quickbooks_oauth_tenant_id'));
    }

    public function test_oauth_state_is_tenant_bound(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $userB->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        app(TenantContext::class)->clear();

        $response = $this->actingAs($userB)
            ->withHeader('X-Tenant-ID', $tenantB->id)
            ->withSession([
                'quickbooks_oauth_state' => 'state-123',
                'quickbooks_oauth_tenant_id' => $this->tenant->id,
            ])
            ->get(route('accounting.quickbooks.callback', [
                'code' => 'auth-code',
                'realmId' => 'realm-123',
                'state' => 'state-123',
            ]));

        $response->assertRedirect(route('accounting.quickbooks.index'))
            ->assertSessionHas('error', 'QuickBooks OAuth tenant context changed.');

        app(TenantContext::class)->setTenant($tenantB);
        $this->assertNull(QuickBooksConnection::query()->first());
        app(TenantContext::class)->clear();
    }

    public function test_oauth_callback_rejects_invalid_state(): void
    {
        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withSession([
                'quickbooks_oauth_state' => 'expected-state',
                'quickbooks_oauth_tenant_id' => $this->tenant->id,
            ])
            ->get(route('accounting.quickbooks.callback', [
                'code' => 'auth-code',
                'realmId' => 'realm-123',
                'state' => 'wrong-state',
            ]));

        $response->assertRedirect(route('accounting.quickbooks.index'))
            ->assertSessionHas('error', 'Invalid QuickBooks OAuth state.');
    }

    public function test_oauth_callback_errors_are_sanitized(): void
    {
        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.callback', [
                'error' => 'access_denied',
                'error_description' => 'Authorization: Bearer super-secret client_secret=top-secret access_token=abc123',
            ]));

        $response->assertRedirect(route('accounting.quickbooks.index'));

        $this->assertStringNotContainsString('super-secret', session('error'));
        $this->assertStringNotContainsString('top-secret', session('error'));
        $this->assertStringNotContainsString('abc123', session('error'));
        $this->assertStringContainsString('Authorization: Bearer [redacted]', session('error'));
    }

    public function test_oauth_callback_stores_tokens_encrypted_and_without_side_effects(): void
    {
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token' => 'plain-access-token',
                'refresh_token' => 'plain-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 86400,
                'token_type' => 'bearer',
            ]),
            'sandbox-quickbooks.api.intuit.test/*' => Http::response(['unexpected' => true], 500),
        ]);

        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();
        $mappingBefore = $this->mappingCount();

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withSession([
                'quickbooks_oauth_state' => 'state-123',
                'quickbooks_oauth_tenant_id' => $this->tenant->id,
            ])
            ->get(route('accounting.quickbooks.callback', [
                'code' => 'auth-code',
                'realmId' => 'realm-123',
                'state' => 'state-123',
            ]));

        $response->assertRedirect(route('accounting.quickbooks.index'));

        $connection = $this->connectionForTenant();
        $this->assertSame(QuickBooksConnection::STATUS_CONNECTED, $connection->status);
        $this->assertSame('realm-123', $connection->realm_id);
        $this->assertSame('plain-access-token', $connection->access_token);
        $this->assertSame('plain-refresh-token', $connection->refresh_token);
        $this->assertTrue($connection->access_token_expires_at->isFuture());
        $this->assertTrue($connection->refresh_token_expires_at->isFuture());
        $this->assertSame('sandbox', $connection->metadata['environment']);

        $raw = DB::table('quickbooks_connections')->where('id', $connection->id)->first();
        $this->assertNotEquals('plain-access-token', $raw->access_token);
        $this->assertNotEquals('plain-refresh-token', $raw->refresh_token);

        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        $this->assertSame($mappingBefore, $this->mappingCount());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com/oauth2/v1/tokens/bearer'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sandbox-quickbooks.api.intuit.test'));

        $this->assertAuditLogsDoNotContainSecrets(['plain-access-token', 'plain-refresh-token', 'test-client-secret']);
    }

    public function test_disconnect_clears_tokens_locally_without_side_effects(): void
    {
        Http::fake();
        $connection = $this->createConnectedConnection();
        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();
        $mappingBefore = $this->mappingCount();

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.quickbooks.disconnect'), ['reason' => 'manual disconnect']);

        $response->assertRedirect(route('accounting.quickbooks.index'));

        $connection->refresh();
        $this->assertSame(QuickBooksConnection::STATUS_DISCONNECTED, $connection->status);
        $this->assertNull($connection->access_token);
        $this->assertNull($connection->refresh_token);
        $this->assertNotNull($connection->disconnected_at);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        $this->assertSame($mappingBefore, $this->mappingCount());
        Http::assertNothingSent();

        $this->assertAuditLogsDoNotContainSecrets(['connected-access-token', 'connected-refresh-token']);
    }

    public function test_reconnect_after_disconnect_works(): void
    {
        $connection = $this->createConnectedConnection();
        $connection->update([
            'status' => QuickBooksConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'disconnected_at' => now(),
        ]);

        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token' => 'reconnected-access-token',
                'refresh_token' => 'reconnected-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 86400,
                'token_type' => 'bearer',
            ]),
        ]);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withSession([
                'quickbooks_oauth_state' => 'state-456',
                'quickbooks_oauth_tenant_id' => $this->tenant->id,
            ])
            ->get(route('accounting.quickbooks.callback', [
                'code' => 'auth-code-2',
                'realmId' => 'realm-456',
                'state' => 'state-456',
            ]))
            ->assertRedirect(route('accounting.quickbooks.index'));

        $connection->refresh();
        $this->assertSame(QuickBooksConnection::STATUS_CONNECTED, $connection->status);
        $this->assertSame('realm-456', $connection->realm_id);
        $this->assertSame('reconnected-access-token', $connection->access_token);
        $this->assertSame('reconnected-refresh-token', $connection->refresh_token);
    }

    public function test_connection_status_page_does_not_expose_raw_tokens(): void
    {
        $this->createConnectedConnection();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/QuickBooks/Connection')
                ->where('connection.connected', true)
                ->where('connection.realm_id', 'realm-123')
                ->where('connection.environment', 'sandbox')
                ->missing('connection.access_token')
                ->missing('connection.refresh_token')
                ->missing('connection.client_secret')
                ->missing('connection.authorization')
            );
    }

    public function test_cashier_pos_routes_remain_accounting_silent(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $cashier->assignToBranch($this->branch);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/Index')
                ->missing('quickbooks')
                ->missing('quickbooks_connection')
                ->missing('accounting')
            );
    }

    protected function createConnectedConnection(array $overrides = []): QuickBooksConnection
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $connection = QuickBooksConnection::create(array_merge([
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'access_token' => 'connected-access-token',
            'refresh_token' => 'connected-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(10),
            'connected_at' => now()->subMinute(),
            'metadata' => ['environment' => 'sandbox', 'token_type' => 'bearer'],
        ], $overrides));

        app(TenantContext::class)->clear();

        return $connection;
    }

    protected function connectionForTenant(): QuickBooksConnection
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $connection = QuickBooksConnection::query()->firstOrFail();
        app(TenantContext::class)->clear();

        return $connection;
    }

    protected function businessCounts(): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $counts = [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'inventory_movements' => InventoryMovement::count(),
            'sale_refunds' => SaleRefund::count(),
            'sale_voids' => SaleVoid::count(),
        ];

        app(TenantContext::class)->clear();

        return $counts;
    }

    protected function outboxCount(): int
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $count = AccountingOutbox::count();
        app(TenantContext::class)->clear();

        return $count;
    }

    protected function mappingCount(): int
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $count = AccountingMapping::count();
        app(TenantContext::class)->clear();

        return $count;
    }

    protected function assertAuditLogsDoNotContainSecrets(array $secrets): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $logs = AuditLog::query()->get();
        app(TenantContext::class)->clear();

        foreach ($logs as $log) {
            $serialized = json_encode([
                'before_values' => $log->before_values,
                'after_values' => $log->after_values,
                'metadata' => $log->metadata,
                'reason' => $log->reason,
                'remarks' => $log->remarks,
            ], JSON_THROW_ON_ERROR);

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }
    }
}