<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemAdminDashboardUITest extends TestCase
{
    use RefreshDatabase;

    protected User $systemAdmin;
    protected User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemAdmin = User::factory()->platformSupport()->create();

        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $this->tenantUser = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_system_admin_can_load_dashboard_ui(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->get(route('system-admin.dashboard.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SystemAdmin/Dashboard/Index')
        );
    }

    public function test_tenant_user_cannot_load_dashboard_ui(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->get(route('system-admin.dashboard.index'));

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_load_dashboard_ui(): void
    {
        $response = $this->get(route('system-admin.dashboard.index'));

        $response->assertRedirect(route('login'));
    }
}
