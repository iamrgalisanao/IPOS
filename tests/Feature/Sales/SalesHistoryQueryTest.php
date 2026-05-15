<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Sales\SalesHistoryQueryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesHistoryQueryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $admin;
    protected User $manager;
    protected SalesHistoryQueryService $queryService;
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

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->admin->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->manager->assignToBranch($this->branchA);

        $this->cash = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'status' => 'active',
        ]);

        $this->queryService = app(SalesHistoryQueryService::class);
        
        app(TenantContext::class)->clear();
    }

    public function test_authorized_user_can_list_tenant_sales(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createSale($this->branchA, '100.00');
        $this->createSale($this->branchB, '200.00');

        $results = $this->queryService->query($this->admin);

        $this->assertCount(2, $results->items());
    }

    public function test_branch_scoped_user_only_sees_assigned_branch(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createSale($this->branchA, '100.00');
        $this->createSale($this->branchB, '200.00');

        $results = $this->queryService->query($this->manager);

        $this->assertCount(1, $results->items());
        $this->assertEquals($this->branchA->id, $results->items()[0]->branch_id);
    }

    public function test_branch_scoped_user_cannot_query_other_branch_even_if_filtered(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createSale($this->branchB, '200.00');

        $results = $this->queryService->query($this->manager, ['branch_id' => $this->branchB->id]);

        $this->assertCount(0, $results->items());
    }

    public function test_tenant_a_cannot_see_tenant_b_sales(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $this->createSale($branchB, '500.00');

        app(TenantContext::class)->setTenant($this->tenant);
        $results = $this->queryService->query($this->admin);

        $this->assertCount(0, $results->items());
    }

    public function test_date_range_filter_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createSale($this->branchA, '100.00', '2026-05-10 10:00:00');
        $this->createSale($this->branchA, '200.00', '2026-05-12 10:00:00');
        $this->createSale($this->branchA, '300.00', '2026-05-15 10:00:00');

        $results = $this->queryService->query($this->admin, [
            'start_date' => '2026-05-11 00:00:00',
            'end_date' => '2026-05-13 23:59:59',
        ]);

        $this->assertCount(1, $results->items());
        $this->assertEquals('200.0000', $results->items()[0]->total);
    }

    public function test_status_filter_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createSale($this->branchA, '100.00', null, 'paid');
        $this->createSale($this->branchA, '200.00', null, 'voided');

        $results = $this->queryService->query($this->admin, ['status' => 'voided']);

        $this->assertCount(1, $results->items());
        $this->assertEquals('voided', $results->items()[0]->status);
    }

    public function test_payment_method_filter_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $otherMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        
        $this->createSaleWithPayment($this->branchA, '100.00', $this->cash->id);
        $this->createSaleWithPayment($this->branchA, '200.00', $otherMethod->id);

        $results = $this->queryService->query($this->admin, ['payment_method_id' => $otherMethod->id]);

        $this->assertCount(1, $results->items());
        $this->assertEquals('200.0000', $results->items()[0]->total);
    }

    public function test_search_by_sale_number_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $sale = $this->createSale($this->branchA, '100.00');
        $sale->update(['sale_number' => 'ABC-123']);

        $results = $this->queryService->query($this->admin, ['search' => 'ABC-123']);

        $this->assertCount(1, $results->items());
        $this->assertEquals('ABC-123', $results->items()[0]->sale_number);
    }

    public function test_search_by_client_uuid_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $uuid = Str::uuid()->toString();
        $this->createSale($this->branchA, '100.00', null, 'paid', $uuid);

        $results = $this->queryService->query($this->admin, ['search' => $uuid]);

        $this->assertCount(1, $results->items());
        $this->assertEquals($uuid, $results->items()[0]->client_request_uuid);
    }

    public function test_ordering_is_by_effective_date_desc(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createSale($this->branchA, '100.00', '2026-05-10 10:00:00');
        $this->createSale($this->branchA, '200.00', '2026-05-15 10:00:00');
        $this->createSale($this->branchA, '300.00', '2026-05-12 10:00:00');

        $results = $this->queryService->query($this->admin);

        $this->assertEquals('200.0000', $results->items()[0]->total);
        $this->assertEquals('300.0000', $results->items()[1]->total);
        $this->assertEquals('100.0000', $results->items()[2]->total);
    }

    protected function createSale(Branch $branch, string $total, ?string $createdAt = null, string $status = 'paid', ?string $uuid = null): Sale
    {
        $data = [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'user_id' => $this->admin->id,
            'client_request_uuid' => $uuid ?? Str::uuid()->toString(),
            'sale_number' => 'SALE-' . strtoupper(Str::random(8)),
            'status' => $status,
            'total' => $total,
            'confirmed_at' => $createdAt, // Set confirmed_at for easier testing of ordering/filtering
        ];

        if ($createdAt) {
            $data['created_at'] = $createdAt;
            $data['updated_at'] = $createdAt;
        }

        return Sale::create($data);
    }

    protected function createSaleWithPayment(Branch $branch, string $total, string $paymentMethodId): Sale
    {
        $sale = $this->createSale($branch, $total);
        
        SalePayment::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $paymentMethodId,
            'payment_type' => 'full',
            'amount' => $total,
            'status' => 'recorded',
            'paid_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        return $sale;
    }
}
