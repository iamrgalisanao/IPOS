<?php

namespace Tests\Feature\Security;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Role;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\SupportAccessSessionService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SensitiveRouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $accountant;
    protected User $cashier;
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
            'email' => 'accountant@example.test',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'email' => 'cashier@example.test',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->supportUser = User::factory()->platformSupport()->create([
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_cannot_access_sensitive_support_accounting_profile_and_export_routes(): void
    {
        $session = $this->createSupportSession();
        $period = $this->createSettlementPeriod($this->tenant, $this->branch);

        $this->getJson(route('support.assisted.audit-events.index', $session))
            ->assertUnauthorized();

        $this->get(route('accounting.quickbooks.index'))
            ->assertRedirect(route('login'));

        $this->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->get(route('settlement.periods.export.summary.csv', $period->id))
            ->assertRedirect(route('login'));

        $this->getJson('/api/accounting/outbox')
            ->assertUnauthorized();
    }

    public function test_support_assisted_routes_require_support_context_and_remain_read_only(): void
    {
        $session = $this->createSupportSession();

        $this->actingAs($this->cashier)
            ->getJson(route('support.assisted.audit-events.index', $session))
            ->assertForbidden()
            ->assertSee('Platform support access required.');

        $this->actingAs($this->supportUser)
            ->postJson(route('support.assisted.probe', $session))
            ->assertForbidden()
            ->assertSee('Support assisted routes are read-only.');
    }

    public function test_quickbooks_management_and_accounting_outbox_api_require_authorization(): void
    {
        $record = $this->createOutboxRecord();

        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.quickbooks.index'))
            ->assertForbidden();

        Sanctum::actingAs($this->cashier);

        $response = $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/accounting/outbox/' . $record->id);

        $response->assertForbidden();
        $response->assertDontSee('api-secret-token');
        $response->assertDontSee('provider_payload');
    }

    public function test_profile_routes_require_authentication_and_remain_self_scoped(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $otherUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Other User',
            'email' => 'other@example.test',
        ]);
        app(TenantContext::class)->clear();

        $this->patch(route('profile.update'), [
            'name' => 'Blocked Guest',
            'email' => 'guest@example.test',
        ])->assertRedirect(route('login'));

        $this->delete(route('profile.destroy'), [
            'password' => 'password',
        ])->assertRedirect(route('login'));

        $this->actingAs($this->accountant)
            ->patch(route('profile.update'), [
                'name' => 'Updated Accountant',
                'email' => 'updated-accountant@example.test',
            ])->assertRedirect(route('profile.edit'));

        $this->assertSame('Updated Accountant', $this->accountant->fresh()->name);
        $this->assertSame('updated-accountant@example.test', $this->accountant->fresh()->email);
        $this->assertSame('Other User', $otherUser->fresh()->name);
        $this->assertSame('other@example.test', $otherUser->fresh()->email);
    }

    public function test_export_routes_require_authorization_and_cannot_cross_tenant_scope(): void
    {
        $period = $this->createSettlementPeriod($this->tenant, $this->branch);

        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.export.summary.csv', $period->id))
            ->assertForbidden();

        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'status' => 'active',
        ]);
        $foreignPeriod = $this->createSettlementPeriod($tenantB, $branchB);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.export.summary.csv', $foreignPeriod->id))
            ->assertNotFound();
    }

    protected function createSupportSession()
    {
        return app(SupportAccessSessionService::class)->startSession(
            supportUser: $this->supportUser,
            tenant: $this->tenant,
            branch: $this->branch,
            reason: 'Sensitive route protection review.'
        );
    }

    protected function createOutboxRecord(): AccountingOutbox
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $record = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => [
                'provider_payload' => ['token' => 'hidden'],
                'access_token' => 'api-secret-token',
            ],
            'sync_status' => 'pending',
        ]);

        app(TenantContext::class)->clear();

        return $record;
    }

    protected function createSettlementPeriod(Tenant $tenant, Branch $branch): SettlementPeriod
    {
        app(TenantContext::class)->setTenant($tenant);

        $period = SettlementPeriod::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'period_start_at' => now()->subDay()->startOfDay(),
            'period_end_at' => now()->subDay()->endOfDay(),
            'status' => SettlementPeriod::STATUS_APPROVED,
        ]);

        app(TenantContext::class)->clear();

        return $period;
    }
}