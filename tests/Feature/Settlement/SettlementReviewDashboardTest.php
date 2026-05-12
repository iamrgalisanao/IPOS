<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\QuickBooksConnection;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SettlementPeriod;
use App\Models\SettlementSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\Settlement\SettlementSnapshotService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettlementReviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected User $branchViewer;
    protected User $cashier;
    protected SettlementPeriodService $periodService;
    protected SettlementSnapshotService $snapshotService;
    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branchA);
        $this->accountant->assignToBranch($this->branchB);

        $reviewerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Settlement Reviewer',
            'description' => 'Read-only settlement review access',
        ]);
        $viewPermission = Permission::where('name', 'view_settlement_periods')->firstOrFail();
        $reviewerRole->permissions()->attach($viewPermission->id);

        $this->branchViewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->branchViewer->assignRole($reviewerRole);
        $this->branchViewer->assignToBranch($this->branchA);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());

        $this->cash = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'status' => 'active',
        ]);

        $this->periodService = app(SettlementPeriodService::class);
        $this->snapshotService = app(SettlementSnapshotService::class);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_authorized_user_can_view_settlement_period_list(): void
    {
        [$period] = $this->createReviewedFixture($this->branchA);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Index')
                ->has('periods.data', 1)
                ->where('periods.data.0.id', $period->id)
                ->where('periods.data.0.status', SettlementPeriod::STATUS_APPROVED)
                ->where('periods.data.0.scope_label', 'Branch A')
                ->where('periods.data.0.latest_variance_count', 2)
            );
    }

    public function test_unauthorized_user_receives_403(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.index'))
            ->assertForbidden();
    }

    public function test_tenant_a_cannot_view_tenant_b_periods(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $foreignTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($foreignTenant);
        app(TenantContext::class)->setTenant($foreignTenant);
        $foreignBranch = Branch::factory()->create(['tenant_id' => $foreignTenant->id, 'status' => 'active']);
        $foreignUser = User::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $foreignUser->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $foreignUser->assignToBranch($foreignBranch);

        $foreignPeriod = app(SettlementPeriodService::class)->create([
            'branch_id' => $foreignBranch->id,
            'period_start_at' => now()->startOfDay()->toDateTimeString(),
            'period_end_at' => now()->endOfDay()->toDateTimeString(),
        ], $foreignUser);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $foreignPeriod->id))
            ->assertNotFound();
    }

    public function test_branch_a_user_cannot_view_branch_b_period(): void
    {
        [$periodA] = $this->createReviewedFixture($this->branchA);
        [$periodB] = $this->createReviewedFixture($this->branchB);

        $this->actingAs($this->branchViewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('periods.data', 1)
                ->where('periods.data.0.id', $periodA->id)
            );

        $this->actingAs($this->branchViewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $periodB->id))
            ->assertNotFound();
    }

    public function test_tenant_wide_user_can_view_tenant_wide_period(): void
    {
        [$period] = $this->createReviewedFixture(null);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('period.scope_label', 'Tenant-wide')
            );
    }

    public function test_list_displays_status_and_scope_metadata(): void
    {
        [$period] = $this->createReviewedFixture($this->branchA);
        [$tenantWidePeriod] = $this->createReviewedFixture(null);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Index')
                ->has('periods.data', 2)
                ->where('periods.data', function ($periods) use ($period, $tenantWidePeriod): bool {
                    $labels = collect($periods)->pluck('scope_label')->sort()->values()->all();
                    $ids = collect($periods)->pluck('id')->all();

                    return $labels === ['Branch A', 'Tenant-wide']
                        && in_array($period->id, $ids, true)
                        && in_array($tenantWidePeriod->id, $ids, true);
                })
            );
    }

    public function test_detail_view_displays_summary_and_variance_and_snapshots(): void
    {
        [$period] = $this->createReviewedFixture($this->branchA);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('period.status', SettlementPeriod::STATUS_APPROVED)
                ->where('period.scope_label', 'Branch A')
                ->where('summary.sales.gross_sales_total', '100.0000')
                ->where('summary.payments.total', '100.0000')
                ->where('summary.accounting_sync.pending', 1)
                ->where('summary.accounting_sync.failed', 1)
                ->where('variance.summary.total_variance_count', 2)
                ->where('variance.summary.by_category.timing_gap', 1)
                ->where('variance.summary.by_category.sync_failure', 1)
                ->has('variance.items', 2)
                ->has('snapshots', 2)
                ->where('lockReadiness.can_lock', true)
                ->where('lockReadiness.has_snapshot', true)
                ->where('lockReadiness.status_is_approved', true)
            );
    }

    public function test_dashboard_does_not_mutate_source_data_or_call_providers(): void
    {
        Http::fake();
        [$period] = $this->createReviewedFixture($this->branchA);

        $countsBefore = $this->counts();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.index'))
            ->assertOk();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk();

        $this->assertSame($countsBefore, $this->counts());
        Http::assertNothingSent();
    }

    public function test_dashboard_does_not_expose_provider_tokens_or_secrets(): void
    {
        [$period] = $this->createReviewedFixture($this->branchA);
        app(TenantContext::class)->setTenant($this->tenant);
        QuickBooksConnection::create([
            'tenant_id' => $this->tenant->id,
            'status' => QuickBooksConnection::STATUS_CONNECTED,
            'realm_id' => 'realm-123',
            'company_name' => 'Sandbox Co',
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addMonth(),
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id));

        $response->assertOk()
            ->assertDontSee('secret-access-token')
            ->assertDontSee('secret-refresh-token')
            ->assertDontSee('client_secret')
            ->assertDontSee('Bearer');
    }

    protected function createReviewedFixture(?Branch $branch): array
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $sourceBranch = $branch ?? $this->branchA;

        $period = $this->periodService->create([
            'branch_id' => $branch?->id,
            'period_start_at' => now()->startOfDay()->toDateTimeString(),
            'period_end_at' => now()->endOfDay()->toDateTimeString(),
        ], $this->accountant);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $sourceBranch->id,
            'user_id' => $this->accountant->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sale_number' => 'SALE-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8)),
            'status' => 'confirmed',
            'subtotal' => '100.0000',
            'tax_total' => '0.0000',
            'discount_total' => '0.0000',
            'total' => '100.0000',
            'confirmed_at' => now()->setTime(9, 0)->toDateTimeString(),
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $sourceBranch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $this->cash->id,
            'payment_type' => 'full',
            'provider' => 'cashier',
            'amount' => '100.0000',
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => now()->setTime(9, 0)->toDateTimeString(),
            'created_by' => $this->accountant->id,
        ]);

        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $sourceBranch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'payload' => [
                'sale_number' => $sale->sale_number,
                'subtotal' => '100.0000',
                'tax_total' => '0.0000',
                'total' => '100.0000',
                'items' => [[
                    'product_id' => 'prod-uuid',
                    'product_name' => 'Burger',
                    'quantity' => '1.0000',
                    'unit_price' => '100.0000',
                    'line_total' => '100.0000',
                ]],
                'taxes' => [],
                'payments' => [[
                    'method' => 'cash-method-uuid',
                    'amount' => '100.0000',
                    'reference' => 'REF-123',
                ]],
            ],
            'sync_status' => 'pending',
            'attempt_count' => 0,
        ]);

        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $sourceBranch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [
                'sale_number' => 'SALE-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8)),
                'subtotal' => '10.0000',
                'tax_total' => '0.0000',
                'total' => '10.0000',
                'items' => [],
                'taxes' => [],
                'payments' => [],
            ],
            'sync_status' => 'failed',
            'sync_error_category' => 'system',
            'sync_error' => 'Provider failed access_token=hidden',
            'attempt_count' => 1,
        ]);

        $this->snapshotService->create($period, $this->accountant);
        $this->snapshotService->create($period, $this->accountant, SettlementSnapshot::TYPE_PRE_LOCK);
        $period = $this->periodService->markInReview($period, $this->accountant);
        $period = $this->periodService->approve($period, $this->accountant);
        $period->refresh()->load(['latestSnapshot', 'snapshots.creator']);

        app(TenantContext::class)->clear();

        return [$period];
    }

    protected function counts(): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        return [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'inventory_movements' => InventoryMovement::count(),
            'accounting_outbox' => AccountingOutbox::count(),
            'settlement_snapshots' => SettlementSnapshot::count(),
        ];
    }
}
