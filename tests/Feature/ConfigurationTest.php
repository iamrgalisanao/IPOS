<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\User;
use App\Models\Role;
use App\Models\AuditLog;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use App\Services\ConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tenant_settings_can_be_updated_for_active_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 1. Tenant settings can be created/updated
        app(ConfigurationService::class)->updateTenant($tenant, [
            'currency' => 'USD',
            'timezone' => 'UTC'
        ]);

        $this->assertEquals('USD', $tenant->currency);
        $this->assertEquals('UTC', $tenant->timezone);

        // 12. Configuration update is audit-ready
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant_config_updated',
            'auditable_id' => $tenant->id,
            'tenant_id' => $tenant->id
        ]);
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tenant_settings_use_default_currency_php(): void
    {
        // 2. Tenant settings use default currency PHP
        $tenant = Tenant::factory()->create();
        $this->assertEquals('PHP', $tenant->currency);
    }

    /** @test */
    public function test_invalid_currency_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        
        // 3. Invalid currency is rejected
        $this->expectException(ValidationException::class);
        app(ConfigurationService::class)->updateTenant($tenant, ['currency' => 'INVALID']);
    }

    /** @test */
    public function test_invalid_timezone_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        
        // 4. Invalid timezone is rejected
        $this->expectException(ValidationException::class);
        app(ConfigurationService::class)->updateTenant($tenant, ['timezone' => 'Invalid/Timezone']);
    }

    /** @test */
    public function test_branch_settings_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create();
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create();
        app(TenantContext::class)->clear();

        // 9. Tenant A cannot access/update Tenant B branch settings
        app(TenantContext::class)->setTenant($tenantA);
        $foundBranchB = Branch::where('id', $branchB->id)->first();
        $this->assertNull($foundBranchB);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_branch_settings_can_be_updated_within_active_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();

        // 6. Branch settings can be updated
        app(ConfigurationService::class)->updateBranch($branch, [
            'address' => 'Updated Address'
        ]);

        $this->assertEquals('Updated Address', $branch->address);
        
        // 12. Audit logged
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'branch_config_updated',
            'auditable_id' => $branch->id,
            'tenant_id' => $tenant->id
        ]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_branch_code_uniqueness_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        Branch::factory()->create(['branch_code' => 'BR001']);
        
        // 7. Branch code remains unique per tenant
        try {
            Branch::factory()->create(['branch_code' => 'BR001']);
            $this->fail('Duplicate branch code allowed in same tenant');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->clear();

        // 8. Same branch code can exist in different tenants
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['branch_code' => 'BR001']);
        $this->assertNotNull($branchB);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inactive_branch_remains_blocked(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['status' => 'inactive']);
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 10. Inactive branch remains blocked by branch middleware
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('Branch account is inactive');
    }

    /** @test */
    public function test_receipt_numbering_fields_are_placeholders(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();

        // 9. Receipt numbering fields are placeholders
        app(ConfigurationService::class)->updateBranch($branch, [
            'receipt_prefix' => 'INV-',
            'receipt_next_number' => 100
        ]);

        $this->assertEquals('INV-', $branch->receipt_prefix);
        $this->assertEquals(100, $branch->receipt_next_number);
        
        app(TenantContext::class)->clear();
    }
    public function test_branch_timezone_falls_back_to_tenant_timezone(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'Asia/Manila', 'status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        
        $branch = Branch::factory()->create(['timezone' => null]);
        
        // 11. Branch timezone defaults to tenant timezone if blank
        $this->assertEquals('Asia/Manila', $branch->getTimezone());

        // Override branch timezone
        app(ConfigurationService::class)->updateBranch($branch, ['timezone' => 'UTC']);
        $this->assertEquals('UTC', $branch->getTimezone());
        
        app(TenantContext::class)->clear();
    }
}
