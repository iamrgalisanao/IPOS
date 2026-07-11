<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TerminalIdentityBindingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected SalesMachineProfile $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        (new RbacSeeder())->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->terminal = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'TERM-01',
            'terminal_identifier' => 'TERM-01',
            'status' => 'active',
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $cashierRole = \App\Models\Role::where('tenant_id', $this->tenant->id)
            ->where('name', 'Cashier')
            ->first();
        $this->cashier->assignRole($cashierRole);
        $this->cashier->assignToBranch($this->branch);

        // Enable terminal binding enforcement for this suite
        config(['app.enforce_terminal_binding' => true]);

        $this->actingAs($this->cashier);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    public function test_valid_terminal_shell_access_renders_checkout()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ])->get(route('pos.terminal.checkout'));

        $response->assertStatus(200);
    }

    public function test_missing_terminal_context_rejects_shell_entry()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ])->get(route('pos.terminal.checkout'));

        $response->assertStatus(403);
    }

    public function test_invalid_terminal_id_rejects_shell_entry()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => 'nonexistent-terminal-uuid',
        ])->get(route('pos.terminal.checkout'));

        $response->assertStatus(403);
    }

    public function test_cross_branch_terminal_rejects_shell_entry()
    {
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        // Set branch context to otherBranch so the model guard allows creation
        app(BranchContext::class)->setBranch($otherBranch);

        $otherTerminal = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'profile_code' => 'TERM-02',
            'terminal_identifier' => 'TERM-02',
            'status' => 'active',
        ]);

        // Restore original branch context for the request
        app(BranchContext::class)->setBranch($this->branch);

        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $otherTerminal->id,
        ])->get(route('pos.terminal.checkout'));

        $response->assertStatus(403);
    }

    public function test_cross_tenant_terminal_rejects_shell_entry()
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        $otherTenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);
        (new RbacSeeder())->seedForTenant($otherTenant);
        app(TenantContext::class)->setTenant($otherTenant);

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($otherBranch);

        $otherTerminal = SalesMachineProfile::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'profile_code' => 'OTHER-TERM',
            'terminal_identifier' => 'OTHER-TERM',
            'status' => 'active',
        ]);

        // Switch back to original tenant/branch context
        app(TenantContext::class)->setTenant($this->tenant);
        app(BranchContext::class)->setBranch($this->branch);

        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $otherTerminal->id,
        ])->get(route('pos.terminal.checkout'));

        $response->assertStatus(403);
    }

    public function test_terminal_identifier_string_also_resolves()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => 'TERM-01',
        ])->get(route('pos.terminal.checkout'));

        $response->assertStatus(200);
    }

    public function test_terminal_middleware_is_applied_to_checkout_route()
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('pos.terminal.checkout');
        $this->assertNotNull($route, 'pos.terminal.checkout route should exist');
        $this->assertContains('terminal', $route->gatherMiddleware());
    }

    public function test_legacy_pos_route_redirects_to_canonical_terminal_checkout()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ])->get(route('pos.index'));

        $response->assertRedirect('/pos/terminal/checkout');
    }

    public function test_pos_search_requires_terminal_context_when_binding_is_enforced()
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ])->get(route('pos.search'));

        $response->assertStatus(403)
            ->assertJsonPath('code', 'TERMINAL_CONTEXT_INVALID');
    }

    public function test_pos_search_allows_verified_terminal_context()
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ])->get(route('pos.search'));

        $response->assertStatus(200);
    }

    public function test_terminal_shift_route_renders_dedicated_surface()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ])->get(route('pos.terminal.shift'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/Terminal/Shift')
                ->where('terminal_context.terminal.id', $this->terminal->id)
            );
    }

    public function test_terminal_sync_status_route_renders_dedicated_surface()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ])->get(route('pos.terminal.sync-status'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/Terminal/SyncStatus')
                ->where('terminal_context.terminal.id', $this->terminal->id)
                ->has('sync_guidance.cashier_message')
            );
    }

    public function test_terminal_settings_route_renders_dedicated_surface()
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ])->get(route('pos.terminal.settings'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/Terminal/Settings')
                ->where('terminal_context.terminal.id', $this->terminal->id)
                ->where('service_worker.expected_cache', 'ipos-terminal-shell-v31-20260711')
                ->where('hardware.adapter', 'noop')
            );
    }

    public function test_terminal_middleware_is_applied_to_all_terminal_surfaces()
    {
        foreach (['checkout', 'shift', 'sync-status', 'settings'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName("pos.terminal.{$name}");
            $this->assertNotNull($route, "pos.terminal.{$name} route should exist");
            $this->assertContains('terminal', $route->gatherMiddleware(), "pos.terminal.{$name} route should enforce terminal middleware");
        }
    }

    public function test_terminal_middleware_is_applied_to_pos_operational_routes()
    {
        foreach ([
            'pos.search',
            'pos.active-shift',
            'pos.layout',
            'pos.unlock',
            'pos.timecard.status',
            'pos.offline-sync.web',
            'pos.checkout.validate',
            'pos.checkout.create-sale',
            'pos.checkout.status',
            'pos.sales.receipt',
            'pos.sales.payments',
            'pos.sales.payments.split',
        ] as $routeName) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "{$routeName} route should exist");
            $this->assertContains('terminal', $route->gatherMiddleware(), "{$routeName} route should enforce terminal middleware");
        }
    }

    public function test_terminal_middleware_is_applied_to_pos_api_sync_routes()
    {
        foreach ([
            'pos.offline-sync',
            'pos.sandbox.validate',
            'pos.submissions.show',
            'pos.submissions.by-sequence',
            'pos.drawer-status',
            'pos.shifts.spot-audits',
            'pos.shifts.drawer-events',
            'pos.discounts.types',
            'pos.discounts.calculate',
            'pos.manager.authorize',
        ] as $routeName) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "{$routeName} API route should exist");
            $this->assertContains('terminal', $route->gatherMiddleware(), "{$routeName} API route should enforce terminal middleware");
        }
    }
}
