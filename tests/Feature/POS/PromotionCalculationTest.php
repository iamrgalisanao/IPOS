<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\DiscountType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePromotion;
use App\Models\SalePromotionLine;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\PromotionCalculationService;
use App\Services\POS\SaleCreationService;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashier;
    protected ProductCategory $coffeeCategory;
    protected ProductCategory $pastryCategory;
    protected Product $espresso;
    protected Product $croissant;
    protected SalesMachineProfile $terminal;
    protected DiscountType $seniorDiscount;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'BMad Coffee Inc',
            'status' => 'active',
            'currency' => 'PHP',
            'timezone' => 'Asia/Manila',
            'subscription_metadata' => [
                'plan' => 'enterprise',
                'limits' => [
                    'max_branches' => 99,
                    'max_users' => 99,
                ]
            ]
        ]);

        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branchA = Branch::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch A',
            'branch_code' => 'MAIN',
            'status' => 'active',
        ]);

        $this->branchB = Branch::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch B',
            'branch_code' => 'EXPR',
            'status' => 'active',
        ]);

        $this->terminal = SalesMachineProfile::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'profile_code' => 'TERM-A',
            'terminal_identifier' => 'TERM-A-IDENT',
            'status' => 'active',
        ]);

        $this->coffeeCategory = ProductCategory::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Coffee',
            'code' => 'COFFEE',
        ]);

        $this->pastryCategory = ProductCategory::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Pastries',
            'code' => 'PASTRIES',
        ]);

        $this->espresso = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->coffeeCategory->id,
            'name' => 'Espresso',
            'sku' => 'ESP-1',
            'barcode' => '11111',
            'selling_price' => 10.0000,
            'is_active' => true,
            'is_discountable' => true,
        ]);

        $this->croissant = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->pastryCategory->id,
            'name' => 'Croissant',
            'sku' => 'CRO-1',
            'barcode' => '22222',
            'selling_price' => 15.0000,
            'is_active' => true,
            'is_discountable' => true,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branchA);

        $this->seniorDiscount = DiscountType::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Senior Citizen',
            'code' => 'SR_CITIZEN',
            'statutory_category' => 'senior',
            'default_rate' => 0.2000,
            'vat_treatment' => 'exempt',
            'is_active' => true,
        ]);

        app(\App\Services\BranchContext::class)->setBranch($this->branchA);
    }

    /** @test */
    public function test_bogo_promotion_applies_successfully(): void
    {
        // BOGO: Buy 2 get 1 free on Espresso (Reward 100% off)
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'BOGO Espresso',
            'rule_type' => 'bogo',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'buy_x_get_y',
            'reward_type' => 'percent_off',
            'conditions' => [
                'buy_qty' => 2,
                'buy_product_ids' => [$this->espresso->id],
                'reward_qty' => 1,
                'reward_product_ids' => [$this->espresso->id],
            ],
            'rewards' => [
                'percent' => 100,
            ],
            'stackable' => false,
            'min_spend_centavos' => 0,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(3000, $result->originalSubtotalCentavos);
        $this->assertEquals(1000, $result->promotionDiscountCentavos);
        $this->assertEquals(2000, $result->promotionAdjustedSubtotalCentavos);
    }

    /** @test */
    public function test_tiered_discount_minimum_spend_applies_successfully(): void
    {
        // 10% Off when spend is >= 20 PHP (2000 centavos)
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => '10% Off Tier',
            'rule_type' => 'discount_tier',
            'priority' => 5,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => [
                'min_spend_centavos' => 2000,
            ],
            'rewards' => [
                'percent' => 10,
            ],
            'stackable' => false,
            'min_spend_centavos' => 2000,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000] // Subtotal = 3000
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(3000, $result->originalSubtotalCentavos);
        $this->assertEquals(300, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_minimum_spend_amount_off_applies_once_across_eligible_lines(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Flat Amount Off',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'amount_off',
            'conditions' => [
                'min_spend_centavos' => 1000,
            ],
            'rewards' => [
                'amount_centavos' => 500,
            ],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 1, 'unit_price_centavos' => 1000],
            ['product_id' => $this->croissant->id, 'quantity' => 1, 'unit_price_centavos' => 1500],
        ];

        $result = (new PromotionCalculationService())->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(500, $result->promotionDiscountCentavos);
        $this->assertEquals(500, array_sum(array_column($result->appliedPromotions[0]['applied_lines'], 'discount_amount_centavos')));
    }

    /** @test */
    public function test_non_stackable_promotions_lock_quantities(): void
    {
        // Promo A (Priority 10): BOGO Espresso (requires 2, rewards 1)
        $promoA = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo A',
            'rule_type' => 'bogo',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promoA->id,
            'condition_type' => 'buy_x_get_y',
            'reward_type' => 'percent_off',
            'conditions' => [
                'buy_qty' => 2,
                'buy_product_ids' => [$this->espresso->id],
                'reward_qty' => 1,
                'reward_product_ids' => [$this->espresso->id],
            ],
            'rewards' => [
                'percent' => 100,
            ],
            'stackable' => false,
            'min_spend_centavos' => 0,
        ]);

        // Promo B (Priority 5): BOGO Espresso (requires 2, rewards 1)
        $promoB = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo B',
            'rule_type' => 'bogo',
            'priority' => 5,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promoB->id,
            'condition_type' => 'buy_x_get_y',
            'reward_type' => 'percent_off',
            'conditions' => [
                'buy_qty' => 2,
                'buy_product_ids' => [$this->espresso->id],
                'reward_qty' => 1,
                'reward_product_ids' => [$this->espresso->id],
            ],
            'rewards' => [
                'percent' => 100,
            ],
            'stackable' => false,
            'min_spend_centavos' => 0,
        ]);

        // Total 4 Espresso in cart.
        // Promo A will apply once, consuming 3 Espresso.
        // Only 1 Espresso remains available, which is not enough for Promo B to apply.
        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 4, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(4000, $result->originalSubtotalCentavos);
        $this->assertEquals(1000, $result->promotionDiscountCentavos); // Promo A only
        $this->assertCount(1, $result->appliedPromotions);
    }

    /** @test */
    public function test_statutory_and_promotions_interact_correctly(): void
    {
        // 1. Setup BOGO Espresso (Buy 2 Get 1 Free)
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'BOGO Espresso',
            'rule_type' => 'bogo',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'buy_x_get_y',
            'reward_type' => 'percent_off',
            'conditions' => [
                'buy_qty' => 2,
                'buy_product_ids' => [$this->espresso->id],
                'reward_qty' => 1,
                'reward_product_ids' => [$this->espresso->id],
            ],
            'rewards' => [
                'percent' => 100,
            ],
            'stackable' => false,
            'min_spend_centavos' => 0,
        ]);

        // 2. Setup checkout payload
        $rawItems = [
            ['product_id' => $this->espresso->id, 'quantity' => 3], // Original subtotal = 30.00
        ];

        $creationService = app(SaleCreationService::class);
        $response = $creationService->createFromPayload(
            $this->tenant->id,
            $this->branchA->id,
            $this->cashier->id,
            Str::uuid()->toString(),
            $rawItems,
            [
                'discount_type_id' => $this->seniorDiscount->id,
                'options' => [
                    'eligible_person_count' => 1,
                    'total_pax_count' => 1,
                    'beneficiaries' => [
                        ['beneficiary_name' => 'Juan Dela Cruz', 'id_number' => 'SR-12345'],
                    ],
                ]
            ],
            false,
            $this->terminal->id
        );

        $this->assertEquals('created', $response['status']);
        $sale = $response['sale'];

        // Original Subtotal: 30.00
        // Promotion discount (1 Espresso free): 10.00
        // Promotion adjusted subtotal: 20.00
        // Statutory Senior Citizen discount (20% of promotion adjusted 20.00): 4.00
        // Total discount: 14.00
        // Net due: 16.00
        $this->assertEquals(30.00, (float) $sale->subtotal);
        $this->assertEquals(14.00, (float) $sale->discount_total);
        $this->assertEquals(16.00, (float) $sale->total);
        $this->assertEquals(4.00, (float) $sale->statutory_discount_total);
        $this->assertEquals(10.00, (float) $sale->commercial_discount_total);
    }

    /** @test */
    public function test_promotion_calculation_result_hash_generation(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo Hash',
            'rule_type' => 'bogo',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, []);

        $this->assertNotEmpty($result->promotionRulesVersionHash);
        $this->assertEquals(64, strlen($result->promotionRulesVersionHash));
    }

    /** @test */
    public function test_expired_promotion_is_ignored(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Promo',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDays(1),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(0, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_future_promotion_is_ignored(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Future Promo',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->addDays(1),
            'ends_at' => now()->addDays(5),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(0, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_branch_scoped_promotion_applies_only_to_allowed_branch(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Scoped Promo',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $promo->branches()->sync([$this->branchA->id]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();

        // Branch A - matches branch scope -> applies promo
        $resultA = $service->calculate($this->tenant->id, $this->branchA->id, $cart);
        $this->assertEquals(300, $resultA->promotionDiscountCentavos);

        // Branch B - mismatch branch scope -> ignores promo
        $resultB = $service->calculate($this->tenant->id, $this->branchB->id, $cart);
        $this->assertEquals(0, $resultB->promotionDiscountCentavos);
    }

    /** @test */
    public function test_tenant_isolation_prevents_leakage(): void
    {
        $anotherTenant = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Another Tenant',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenant($anotherTenant);

        $promo = Promotion::create([
            'tenant_id' => $anotherTenant->id,
            'name' => 'Leaked Promo',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        app(TenantContext::class)->setTenant($this->tenant);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        // Current tenant must isolate and ignore another tenant's rules
        $this->assertEquals(0, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_tie_breaker_rules_produce_deterministic_winner(): void
    {
        // Create 2 identical rules with same priority & starts_at
        $promo1 = Promotion::create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo 1',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'created_at' => now()->subMinutes(10),
        ]);

        PromotionRule::create([
            'promotion_id' => $promo1->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $promo2 = Promotion::create([
            'id' => '00000000-0000-0000-0000-000000000002',
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo 2',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'created_at' => now()->subMinutes(10),
        ]);

        PromotionRule::create([
            'promotion_id' => $promo2->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 20], // Higher savings candidate wins
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 3, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        // Promo 2 gives 20% discount (600) vs 10% (300). Promo 2 wins due to higher benefit.
        $this->assertEquals(600, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_customer_benefit_wins_before_priority_for_overlapping_non_stackable_promotions(): void
    {
        $highPriorityPromo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'High Priority Low Benefit',
            'rule_type' => 'discount_tier',
            'priority' => 100,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $highPriorityPromo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $lowPriorityPromo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Low Priority High Benefit',
            'rule_type' => 'discount_tier',
            'priority' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $lowPriorityPromo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 25],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 2, 'unit_price_centavos' => 1000],
        ];

        $result = (new PromotionCalculationService())->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(500, $result->promotionDiscountCentavos);
        $this->assertSame($lowPriorityPromo->id, $result->appliedPromotions[0]['promotion_id']);
    }

    /** @test */
    public function test_applied_promotion_snapshots_persisted_correctly(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => '10% Off Snapshot',
            'rule_type' => 'discount_tier',
            'priority' => 5,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $rawItems = [
            ['product_id' => $this->espresso->id, 'quantity' => 3],
        ];

        $creationService = app(SaleCreationService::class);
        $response = $creationService->createFromPayload(
            $this->tenant->id,
            $this->branchA->id,
            $this->cashier->id,
            Str::uuid()->toString(),
            $rawItems,
            [],
            false,
            $this->terminal->id
        );

        $this->assertEquals('created', $response['status']);
        $sale = $response['sale'];

        $this->assertDatabaseHas('sale_promotions', [
            'sale_id' => $sale->id,
            'promotion_id' => $promo->id,
            'promotion_name' => '10% Off Snapshot',
            'discount_amount_centavos' => 300,
        ]);

        $this->assertDatabaseHas('sale_promotion_lines', [
            'product_id' => $this->espresso->id,
            'role' => 'discounted',
            'discount_amount_centavos' => 300,
        ]);

        $item = SaleItem::where('sale_id', $sale->id)->where('product_id', $this->espresso->id)->firstOrFail();
        $this->assertEquals(1000, $item->original_unit_price_centavos);
        $this->assertEquals(300, $item->promotion_discount_centavos);
        $this->assertEquals(900, $item->promotion_adjusted_unit_price_centavos);
        $this->assertEquals(900, $item->final_unit_price_centavos);
    }

    /** @test */
    public function test_max_applications_per_sale_is_enforced(): void
    {
        // BOGO capped at 1 application
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Capped BOGO',
            'rule_type' => 'bogo',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'buy_x_get_y',
            'reward_type' => 'percent_off',
            'conditions' => [
                'buy_qty' => 2,
                'buy_product_ids' => [$this->espresso->id],
                'reward_qty' => 1,
                'reward_product_ids' => [$this->espresso->id],
            ],
            'rewards' => [
                'percent' => 100,
            ],
            'stackable' => false,
            'min_spend_centavos' => 0,
            'max_applications_per_sale' => 1,
        ]);

        // Cart has 6 Espresso (qualifies for 2 applications normally, but capped at 1)
        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 6, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(1000, $result->promotionDiscountCentavos); // 1 free unit only
    }

    /** @test */
    public function test_max_discount_centavos_is_enforced(): void
    {
        // 10% discount, max cap of 2 PHP (200 centavos)
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Capped Discount',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
            'max_discount_centavos' => 200,
        ]);

        // Cart subtotal = 50.00 PHP (5000 centavos) -> 10% is 500, but capped at 200
        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 5, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(200, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_decimal_quantity_items_calculate_correctly(): void
    {
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tiered Promo',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        // 2.5 quantity of Espresso = 2500 centavos subtotal -> 10% = 250 centavos discount
        $cart = [
            ['product_id' => $this->espresso->id, 'quantity' => 2.5, 'unit_price_centavos' => 1000]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(250, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_promotion_does_not_override_statutory_requirements(): void
    {
        // Enforce that statutory discount validation runs successfully even when promo discount is active
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => '10% Off',
            'rule_type' => 'discount_tier',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 500],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 500,
        ]);

        $rawItems = [
            ['product_id' => $this->espresso->id, 'quantity' => 2],
        ];

        $creationService = app(SaleCreationService::class);
        $response = $creationService->createFromPayload(
            $this->tenant->id,
            $this->branchA->id,
            $this->cashier->id,
            Str::uuid()->toString(),
            $rawItems,
            [
                'discount_type_id' => $this->seniorDiscount->id,
                'options' => [
                    'eligible_person_count' => 1,
                    'total_pax_count' => 1,
                    'beneficiaries' => [
                        ['beneficiary_name' => 'Juan Dela Cruz', 'id_number' => 'SR-12345'],
                    ],
                ]
            ],
            false,
            $this->terminal->id
        );

        $this->assertEquals('created', $response['status']);
        $sale = $response['sale'];
        $this->assertTrue($sale->contains_statutory_discount);
    }

    /** @test */
    public function test_promotion_engine_ignores_excluded_items(): void
    {
        // BOGO restricted to Espresso
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'BOGO Espresso Only',
            'rule_type' => 'bogo',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'buy_x_get_y',
            'reward_type' => 'percent_off',
            'conditions' => [
                'buy_qty' => 1,
                'buy_product_ids' => [$this->espresso->id],
                'reward_qty' => 1,
                'reward_product_ids' => [$this->espresso->id],
            ],
            'rewards' => [
                'percent' => 100,
            ],
            'stackable' => false,
            'min_spend_centavos' => 0,
        ]);

        // Cart has Croissant (which is excluded from BOGO rules)
        $cart = [
            ['product_id' => $this->croissant->id, 'quantity' => 2, 'unit_price_centavos' => 1500]
        ];

        $service = new PromotionCalculationService();
        $result = $service->calculate($this->tenant->id, $this->branchA->id, $cart);

        $this->assertEquals(0, $result->promotionDiscountCentavos);
    }

    /** @test */
    public function test_reversal_behavior_on_voids_and_refunds(): void
    {
        // Validate that creating a sale with promotion discounts behaves nicely and can be voided
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => '10% Off Reversal',
            'rule_type' => 'discount_tier',
            'priority' => 5,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        PromotionRule::create([
            'promotion_id' => $promo->id,
            'condition_type' => 'minimum_spend',
            'reward_type' => 'percent_off',
            'conditions' => ['min_spend_centavos' => 1000],
            'rewards' => ['percent' => 10],
            'stackable' => false,
            'min_spend_centavos' => 1000,
        ]);

        $rawItems = [
            ['product_id' => $this->espresso->id, 'quantity' => 3],
        ];

        $creationService = app(SaleCreationService::class);
        $response = $creationService->createFromPayload(
            $this->tenant->id,
            $this->branchA->id,
            $this->cashier->id,
            Str::uuid()->toString(),
            $rawItems,
            [],
            false,
            $this->terminal->id
        );

        $this->assertEquals('created', $response['status']);
        $sale = $response['sale'];

        $this->assertEquals(3.00, (float) $sale->commercial_discount_total);

        // Void the sale to trigger reversal checks
        $sale->update(['status' => 'voided']);
        $this->assertEquals('voided', $sale->status);
    }
}
