<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Tax\SalesTaxReportingQueryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class TaxReportingBackOfficeUiTest extends TestCase
{
    use RefreshDatabase;

    protected const SUMMARY_KEYS = [
        'tenant_id',
        'branch_id',
        'date_from',
        'date_to',
        'gross_sales',
        'net_sales',
        'vatable_sales',
        'vat_exempt_sales',
        'zero_rated_sales',
        'non_vat_sales',
        'vat_amount',
        'statutory_discount_amount',
        'regular_discount_amount',
        'refund_adjustment_amount',
        'void_adjustment_amount',
        'reversal_adjustment_amount',
        'net_adjustment_amount',
        'refund_count',
        'void_count',
        'reversal_count',
        'reviewed_period_count',
        'locked_period_count',
        'has_reviewed_period',
        'has_locked_period',
        'transaction_count',
    ];

    protected Tenant $tenant;
    protected Tenant $otherTenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $branchManager;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        $this->otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(RbacSeeder::class)->seedForTenant($this->otherTenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branchA);

        $this->branchManager = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->branchManager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->branchManager->assignToBranch($this->branchA);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branchA);

        $this->seedReportingData();
        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_unauthenticated_users_are_redirected_from_tax_reporting_page(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_user_cannot_access_tax_reporting_page(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_access_tax_reporting_page_and_view_summary_contract(): void
    {
        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index', [
                'date_from' => '2026-05-12',
                'date_to' => '2026-05-13',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Tax/Index')
                ->where('filters.date_from', '2026-05-12')
                ->where('filters.date_to', '2026-05-13')
                ->where('filters.branch_id', null)
                ->has('branches', 2)
                ->has('sections', 5)
                ->where('sections.0.title', 'Sales Summary')
                ->where('sections.1.title', 'Tax Bucket Breakdown')
                ->where('sections.2.title', 'Discount Breakdown')
                ->where('sections.3.title', 'Adjustment / Reversal Breakdown')
                ->where('sections.4.title', 'Review / Lock Awareness')
                ->where('sections.0.items.0.key', 'gross_sales')
                ->where('sections.1.items.4.key', 'vat_amount')
                ->where('sections.2.items.0.key', 'statutory_discount_amount')
                ->where('sections.3.items.3.key', 'net_adjustment_amount')
                ->where('sections.4.items.2.label', 'Reviewed period detected')
                ->where('sections.4.items.3.label', 'Locked period detected')
                ->where('summary.tenant_id', $this->tenant->id)
                ->where('summary.branch_id', null)
                ->where('summary.gross_sales', '150.0000')
                ->where('summary.net_sales', '146.0000')
                ->where('summary.transaction_count', 2)
                ->has('summary.gross_sales')
                ->has('summary.net_sales')
                ->has('summary.vatable_sales')
                ->has('summary.vat_exempt_sales')
                ->has('summary.zero_rated_sales')
                ->has('summary.non_vat_sales')
                ->has('summary.vat_amount')
                ->has('summary.statutory_discount_amount')
                ->has('summary.regular_discount_amount')
                ->has('summary.refund_adjustment_amount')
                ->has('summary.void_adjustment_amount')
                ->has('summary.reversal_adjustment_amount')
                ->has('summary.net_adjustment_amount')
                ->has('summary.refund_count')
                ->has('summary.void_count')
                ->has('summary.reversal_count')
                ->has('summary.reviewed_period_count')
                ->has('summary.locked_period_count')
                ->has('summary.has_reviewed_period')
                ->has('summary.has_locked_period')
                ->missing('exportActions')
            );
    }

    public function test_page_uses_sales_tax_reporting_query_service_and_accepts_safe_filters(): void
    {
        $mock = Mockery::mock(SalesTaxReportingQueryService::class);
        $mock->shouldReceive('summarize')
            ->once()
            ->with($this->tenant->id, '2026-05-12 00:00:00', '2026-05-13 23:59:59', $this->branchA->id)
            ->andReturn($this->mockSummary($this->branchA->id));

        app()->instance(SalesTaxReportingQueryService::class, $mock);

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index', [
                'date_from' => '2026-05-12',
                'date_to' => '2026-05-13',
                'branch_id' => $this->branchA->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Tax/Index')
                ->where('filters.branch_id', $this->branchA->id)
                ->where('sections.1.items.0.key', 'vatable_sales')
                ->where('sections.2.items.1.key', 'regular_discount_amount')
                ->where('sections.3.items.0.key', 'void_adjustment_amount')
                ->where('sections.4.items.3.key', 'has_locked_period')
                ->where('summary.gross_sales', '321.0000')
                ->where('summary.vatable_sales', '200.0000')
                ->where('summary.statutory_discount_amount', '10.0000')
                ->where('summary.void_adjustment_amount', '2.0000')
                ->where('summary.has_locked_period', true)
            );
    }

    public function test_branch_scope_is_enforced_for_branch_scoped_users(): void
    {
        $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index', [
                'date_from' => '2026-05-12',
                'date_to' => '2026-05-13',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Tax/Index')
                ->where('filters.branch_id', $this->branchA->id)
                ->has('branches', 1)
                ->where('branches.0.id', $this->branchA->id)
                ->where('branches.0.name', 'Branch A')
                ->where('summary.branch_id', $this->branchA->id)
                ->where('summary.gross_sales', '100.0000')
                ->where('summary.transaction_count', 1)
            );

        $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index', [
                'date_from' => '2026-05-12',
                'date_to' => '2026-05-13',
                'branch_id' => $this->branchB->id,
            ]))
            ->assertNotFound();
    }

    public function test_empty_state_renders_safe_zero_value_summary_and_sections(): void
    {
        $mock = Mockery::mock(SalesTaxReportingQueryService::class);
        $mock->shouldReceive('summarize')
            ->once()
            ->with($this->tenant->id, '2026-05-20 00:00:00', '2026-05-20 23:59:59', null)
            ->andReturn($this->zeroSummary());

        app()->instance(SalesTaxReportingQueryService::class, $mock);

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index', [
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-20',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Tax/Index')
                ->has('sections', 5)
                ->where('summary.gross_sales', '0.0000')
                ->where('summary.net_sales', '0.0000')
                ->where('summary.transaction_count', 0)
                ->where('summary.refund_count', 0)
                ->where('summary.void_count', 0)
                ->where('summary.reversal_count', 0)
                ->where('summary.reviewed_period_count', 0)
                ->where('summary.locked_period_count', 0)
                ->where('summary.has_reviewed_period', false)
                ->where('summary.has_locked_period', false)
                ->missing('exportActions')
            );
    }

    public function test_tax_reporting_page_is_read_only(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $salesBefore = Sale::count();
        $saleItemsBefore = SaleItem::count();

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.index', [
                'date_from' => '2026-05-12',
                'date_to' => '2026-05-13',
            ]))
            ->assertOk();

        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertSame($salesBefore, Sale::count());
        $this->assertSame($saleItemsBefore, SaleItem::count());
    }

    protected function seedReportingData(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $saleA = $this->createSale($this->tenant->id, $this->branchA->id, $this->owner->id, 'SALE-A', '100.0000', '100.0000', '12.0000', '4.0000', '2026-05-12 09:00:00');
        $saleB = $this->createSale($this->tenant->id, $this->branchB->id, $this->owner->id, 'SALE-B', '50.0000', '50.0000', '6.0000', '0.0000', '2026-05-13 10:00:00');
        $this->createSaleItem($this->tenant->id, $this->branchA->id, $saleA->id, 'vatable', '100.0000', '100.0000', '0.0000', '0.0000', '0.0000');
        $this->createSaleItem($this->tenant->id, $this->branchB->id, $saleB->id, 'vatable', '50.0000', '50.0000', '0.0000', '0.0000', '0.0000');

        app(TenantContext::class)->setTenant($this->otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->otherTenant->id, 'status' => 'active', 'name' => 'Other Branch']);
        $otherOwner = User::factory()->create(['tenant_id' => $this->otherTenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $otherOwner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $otherOwner->assignToBranch($otherBranch);
        $otherSale = $this->createSale($this->otherTenant->id, $otherBranch->id, $otherOwner->id, 'OTHER-SALE', '999.0000', '999.0000', '0.0000', '0.0000', '2026-05-12 11:00:00');
        $this->createSaleItem($this->otherTenant->id, $otherBranch->id, $otherSale->id, 'vatable', '999.0000', '999.0000', '0.0000', '0.0000', '0.0000');

        app(TenantContext::class)->setTenant($this->tenant);
    }

    protected function createSale(string $tenantId, string $branchId, string $userId, string $saleNumber, string $gross, string $subtotal, string $vatAmount, string $commercialDiscount, string $reportingBasisAt): Sale
    {
        return Sale::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => $saleNumber,
            'status' => 'paid',
            'subtotal' => $subtotal,
            'tax_total' => $vatAmount,
            'discount_total' => $commercialDiscount,
            'total' => $gross,
            'gross_sales_amount' => $gross,
            'vatable_sales_amount' => '0.0000',
            'vat_exempt_sales_amount' => '0.0000',
            'zero_rated_sales_amount' => '0.0000',
            'non_vat_sales_amount' => '0.0000',
            'vat_amount' => $vatAmount,
            'statutory_discount_total' => '0.0000',
            'commercial_discount_total' => $commercialDiscount,
            'other_adjustment_total' => '0.0000',
            'contains_statutory_discount' => false,
            'reporting_basis_at' => $reportingBasisAt,
            'confirmed_at' => $reportingBasisAt,
        ]);
    }

    protected function createSaleItem(string $tenantId, string $branchId, string $saleId, string $taxBucket, string $netAmount, string $vatableAmount, string $vatExemptAmount, string $zeroRatedAmount, string $nonVatAmount): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $tenantId,
            'selling_price' => $netAmount,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);

        SaleItem::insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit_of_measure' => $product->unit_of_measure,
            'quantity' => '1.0000',
            'unit_price' => $netAmount,
            'subtotal' => $netAmount,
            'discount_amount' => '0.0000',
            'tax_category_id' => null,
            'tax_type' => $taxBucket,
            'tax_bucket' => $taxBucket,
            'tax_rate' => '12.0000',
            'tax_amount' => '0.0000',
            'net_amount' => $netAmount,
            'vatable_amount' => $vatableAmount,
            'vat_exempt_amount' => $vatExemptAmount,
            'zero_rated_amount' => $zeroRatedAmount,
            'non_vat_amount' => $nonVatAmount,
            'tax_source' => 'system',
            'tax_snapshot' => json_encode(['tax_bucket' => $taxBucket], JSON_THROW_ON_ERROR),
            'line_total' => $netAmount,
            'is_inventory_tracked' => false,
            'created_at' => now(),
        ]);
    }

    protected function mockSummary(?string $branchId): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'date_from' => '2026-05-12 00:00:00',
            'date_to' => '2026-05-13 23:59:59',
            'gross_sales' => '321.0000',
            'net_sales' => '300.0000',
            'vatable_sales' => '200.0000',
            'vat_exempt_sales' => '40.0000',
            'zero_rated_sales' => '30.0000',
            'non_vat_sales' => '20.0000',
            'vat_amount' => '21.0000',
            'statutory_discount_amount' => '10.0000',
            'regular_discount_amount' => '11.0000',
            'refund_adjustment_amount' => '1.0000',
            'void_adjustment_amount' => '2.0000',
            'reversal_adjustment_amount' => '3.0000',
            'net_adjustment_amount' => '6.0000',
            'refund_count' => 1,
            'void_count' => 1,
            'reversal_count' => 1,
            'reviewed_period_count' => 0,
            'locked_period_count' => 1,
            'has_reviewed_period' => false,
            'has_locked_period' => true,
            'transaction_count' => 4,
        ];
    }

    protected function zeroSummary(): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'branch_id' => null,
            'date_from' => '2026-05-20 00:00:00',
            'date_to' => '2026-05-20 23:59:59',
            'gross_sales' => '0.0000',
            'net_sales' => '0.0000',
            'vatable_sales' => '0.0000',
            'vat_exempt_sales' => '0.0000',
            'zero_rated_sales' => '0.0000',
            'non_vat_sales' => '0.0000',
            'vat_amount' => '0.0000',
            'statutory_discount_amount' => '0.0000',
            'regular_discount_amount' => '0.0000',
            'refund_adjustment_amount' => '0.0000',
            'void_adjustment_amount' => '0.0000',
            'reversal_adjustment_amount' => '0.0000',
            'net_adjustment_amount' => '0.0000',
            'refund_count' => 0,
            'void_count' => 0,
            'reversal_count' => 0,
            'reviewed_period_count' => 0,
            'locked_period_count' => 0,
            'has_reviewed_period' => false,
            'has_locked_period' => false,
            'transaction_count' => 0,
        ];
    }
}