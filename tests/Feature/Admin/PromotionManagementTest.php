<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenants;

class PromotionManagementTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants;

    protected User $admin;
    protected User $cashier;
    protected Branch $branch;
    protected ProductCategory $category;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->setupTenantContext();

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Branch',
            'branch_code' => 'MAIN',
            'status' => 'active',
        ]);

        $this->category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Coffee',
            'status' => 'active',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Espresso',
            'status' => 'active',
        ]);

        $this->admin = $this->createTenantUser('admin', ['email' => 'promo-admin@example.com']);
        $this->admin->assignToBranch($this->branch);
        $this->givePermissionTo($this->admin, 'manage_promotions');

        $this->cashier = $this->createTenantUser('cashier', ['email' => 'promo-cashier@example.com']);
    }

    public function test_unauthorized_user_cannot_access_promotions_index(): void
    {
        $response = $this->actingAs($this->cashier)
            ->get(route('admin.promotions.index'));

        $response->assertStatus(403);
    }

    public function test_authorized_admin_can_access_promotions_index(): void
    {
        Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Morning Coffee',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Promotions/Index')
            ->has('promotions', 1)
            ->has('branches', 1)
            ->has('products', 1)
            ->has('categories', 1)
        );
    }

    public function test_admin_can_store_minimum_spend_promotion(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.promotions.store'), $this->minimumSpendPayload([
                'name' => 'Ten Percent Coffee',
                'branch_ids' => [$this->branch->id],
            ]));

        $response->assertRedirect();

        $promotion = Promotion::where('name', 'Ten Percent Coffee')->firstOrFail();

        $this->assertDatabaseHas('promotions', [
            'id' => $promotion->id,
            'tenant_id' => $this->tenant->id,
            'rule_type' => 'discount_tier',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('promotion_branches', [
            'promotion_id' => $promotion->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->assertDatabaseHas('promotion_rules', [
            'promotion_id' => $promotion->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
        ]);
    }

    public function test_admin_cannot_store_promotion_for_another_tenant_branch(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.promotions.store'), $this->minimumSpendPayload([
                'branch_ids' => [$otherBranch->id],
            ]));

        $response->assertSessionHasErrors('branch_ids.0');

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_rules', 0);
    }

    public function test_admin_cannot_store_promotion_referencing_another_tenant_product(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherCategory = ProductCategory::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
        ]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'product_category_id' => $otherCategory->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.promotions.store'), $this->minimumSpendPayload([
                'branch_ids' => [$this->branch->id],
                'conditions' => [
                    'min_spend_centavos' => 1000,
                    'eligible_product_ids' => [$otherProduct->id],
                    'eligible_category_ids' => [],
                ],
            ]));

        $response->assertSessionHasErrors('conditions');

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_rules', 0);
    }

    public function test_admin_cannot_store_mismatched_rule_type_and_condition_type(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.promotions.store'), $this->minimumSpendPayload([
                'branch_ids' => [$this->branch->id],
                'rule_type' => 'bogo',
                'condition_type' => 'minimum_spend',
            ]));

        $response->assertSessionHasErrors('condition_type');

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_rules', 0);
    }

    public function test_branch_scoped_manager_cannot_create_global_promotion(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.promotions.store'), $this->minimumSpendPayload([
                'branch_ids' => [],
            ]));

        $response->assertStatus(403);

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_rules', 0);
    }

    public function test_admin_can_deactivate_promotion(): void
    {
        $promotion = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deactivatable Promo',
            'rule_type' => 'discount_tier',
            'priority' => 5,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $promotion->branches()->sync([$this->branch->id]);

        PromotionRule::create([
            'promotion_id' => $promotion->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.promotions.destroy', $promotion->id));

        $response->assertRedirect();

        $this->assertDatabaseHas('promotions', [
            'id' => $promotion->id,
            'is_active' => false,
            'deleted_at' => null,
        ]);
    }

    protected function minimumSpendPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Minimum Spend Promo',
            'description' => 'Configured from promotion admin.',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'is_active' => true,
            'currency' => 'PHP',
            'timezone' => 'Asia/Manila',
            'branch_ids' => [],
            'schema_version' => 'v1',
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => [
                'min_spend_centavos' => 1000,
                'eligible_product_ids' => [$this->product->id],
                'eligible_category_ids' => [],
            ],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
            'max_applications_per_sale' => null,
            'max_discount_centavos' => null,
            'exclusive_group' => null,
        ], $overrides);
    }
}
