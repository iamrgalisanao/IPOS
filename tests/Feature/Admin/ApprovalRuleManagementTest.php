<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\DiscountType;
use App\Models\Tenant;
use App\Services\BranchContext;
use App\Services\POS\ApprovalRuleResolver;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalRuleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_discount_type_minimum_cannot_be_weakened(): void
    {
        [$tenant, $branch] = $this->context();
        $type = $this->type(true);
        ApprovalRule::create([
            'tenant_id' => $tenant->id, 'scope_key' => 'tenant',
            'action' => ApprovalRule::ACTION_STATUTORY_DISCOUNT,
            'always_require_approval' => false,
        ]);

        $resolved = app(ApprovalRuleResolver::class)->resolve($tenant->id, $branch->id, $type);
        $this->assertTrue($resolved['required']);
    }

    public function test_branch_rule_deterministically_strengthens_tenant_default(): void
    {
        [$tenant, $branch] = $this->context();
        $type = $this->type(false);
        ApprovalRule::create([
            'tenant_id' => $tenant->id, 'scope_key' => 'tenant',
            'action' => ApprovalRule::ACTION_STATUTORY_DISCOUNT, 'always_require_approval' => false,
        ]);
        $branchRule = ApprovalRule::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'scope_key' => 'branch:' . $branch->id,
            'action' => ApprovalRule::ACTION_STATUTORY_DISCOUNT, 'always_require_approval' => true,
        ]);

        $resolved = app(ApprovalRuleResolver::class)->resolve($tenant->id, $branch->id, $type);
        $this->assertTrue($resolved['required']);
        $this->assertSame($branchRule->id, $resolved['rule_id']);
        $this->assertSame('branch', $resolved['source']);
    }

    public function test_branch_rule_cannot_weaken_stricter_tenant_default(): void
    {
        [$tenant, $branch] = $this->context();
        $type = $this->type(false);
        ApprovalRule::create([
            'tenant_id' => $tenant->id, 'scope_key' => 'tenant',
            'action' => ApprovalRule::ACTION_STATUTORY_DISCOUNT, 'always_require_approval' => true,
        ]);
        ApprovalRule::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'scope_key' => 'branch:' . $branch->id,
            'action' => ApprovalRule::ACTION_STATUTORY_DISCOUNT, 'always_require_approval' => false,
        ]);

        $this->assertTrue(app(ApprovalRuleResolver::class)->resolve($tenant->id, $branch->id, $type)['required']);
    }

    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(BranchContext::class)->setBranch($branch);
        return [$tenant, $branch];
    }

    private function type(bool $requires): DiscountType
    {
        return DiscountType::create([
            'code' => 'RULE-' . ($requires ? 'REQ' : 'OPTIONAL'), 'name' => 'Rule test',
            'statutory_category' => 'other', 'default_rate' => .1, 'vat_treatment' => 'none',
            'requires_identity' => false, 'requires_approval' => $requires, 'is_active' => true,
        ]);
    }
}
