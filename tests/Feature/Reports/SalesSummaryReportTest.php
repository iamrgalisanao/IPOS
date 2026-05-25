<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesSummaryReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $manager;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'name' => 'Express Branch',
        ]);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Branch Manager',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->manager->assignToBranch($this->branch);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Owner Admin',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

        app(TenantContext::class)->clear();
    }

    public function test_authorized_user_can_view_sales_summary_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $cash = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
        ]);

        $sale = $this->sale([
            'sale_number' => 'SALE-001',
            'status' => 'paid',
            'subtotal' => 100,
            'gross_sales_amount' => 100,
            'discount_total' => 10,
            'tax_total' => 12,
            'total' => 102,
        ]);
        $this->payment($sale, $cash, 102);

        $this->sale([
            'sale_number' => 'SALE-002',
            'status' => 'created',
            'subtotal' => 50,
            'gross_sales_amount' => 50,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 50,
        ]);
        $this->sale([
            'sale_number' => 'SALE-003',
            'status' => 'paid',
            'subtotal' => -20,
            'gross_sales_amount' => -20,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => -20,
            'is_reversal' => true,
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.sales-summary.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Reports/SalesSummary/Index')
            ->where('kpis.transaction_count', 3)
            ->where('kpis.gross_sales', 130)
            ->where('kpis.net_sales', 132)
            ->where('kpis.paid_count', 2)
            ->where('kpis.pending_count', 1)
            ->where('kpis.void_refund_count', 1)
            ->where('kpis.average_transaction_value', 44)
            ->has('payment_breakdown', 1)
            ->where('payment_breakdown.0.payment_method_name', 'Cash')
            ->where('payment_breakdown.0.total_amount', 102)
            ->has('status_breakdown', 2)
            ->where('meta.can_export', false)
        );
    }

    public function test_unauthorized_user_cannot_view_sales_summary_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->get(route('reports.sales-summary.index'));

        $response->assertForbidden();
    }

    public function test_branch_scoping_fail_closes_for_unassigned_branch_filter(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hidden Branch',
        ]);

        $this->sale([
            'branch_id' => $this->branch->id,
            'sale_number' => 'VISIBLE-001',
            'status' => 'paid',
            'total' => 100,
        ]);
        $this->sale([
            'branch_id' => $otherBranch->id,
            'sale_number' => 'HIDDEN-001',
            'status' => 'paid',
            'total' => 999,
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.sales-summary.index', ['branch_id' => $otherBranch->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('kpis.transaction_count', 0)
            ->where('kpis.net_sales', 0)
            ->has('recent_transactions', 0)
        );
    }

    public function test_payment_and_status_filters_narrow_the_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $cash = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cash']);
        $card = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Card']);

        $paidSale = $this->sale(['status' => 'paid', 'total' => 120]);
        $this->payment($paidSale, $cash, 120);

        $voidSale = $this->sale(['status' => 'voided', 'total' => 80]);
        $this->payment($voidSale, $card, 80);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.sales-summary.index', [
                'status' => 'paid',
                'payment_method_id' => $cash->id,
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('kpis.transaction_count', 1)
            ->where('kpis.net_sales', 120)
            ->where('payment_breakdown.0.payment_method_name', 'Cash')
            ->where('payment_breakdown.0.total_amount', 120)
            ->has('status_breakdown', 1)
            ->where('status_breakdown.0.status', 'paid')
        );
    }

    public function test_export_is_permission_gated_and_sanitizes_formula_cells(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $dangerousPayment = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => '=Cash',
        ]);
        $sale = $this->sale(['status' => 'paid', 'total' => 200]);
        $this->payment($sale, $dangerousPayment, 200);

        $managerResponse = $this->actingAs($this->manager)
            ->get(route('reports.sales-summary.export'));
        $managerResponse->assertForbidden();

        $ownerResponse = $this->actingAs($this->owner)
            ->get(route('reports.sales-summary.export'));

        $ownerResponse->assertOk();
        $this->assertStringStartsWith('text/csv', $ownerResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('IPOS Sales Summary Report', $ownerResponse->getContent());
        $this->assertStringContainsString("'=Cash", $ownerResponse->getContent());
        $this->assertStringContainsString('This report summarizes existing sales records only', $ownerResponse->getContent());
    }

    protected function sale(array $overrides = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->manager->id,
            'status' => 'paid',
            'subtotal' => 0,
            'gross_sales_amount' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'reporting_basis_at' => now(),
            'confirmed_at' => now(),
        ], $overrides));
    }

    protected function payment(Sale $sale, PaymentMethod $paymentMethod, float $amount): SalePayment
    {
        return SalePayment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'payment_method_id' => $paymentMethod->id,
            'shift_id' => null,
            'created_by' => $this->manager->id,
            'amount' => $amount,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
