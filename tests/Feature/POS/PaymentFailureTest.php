<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentFailureTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Sale $sale;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $digitalMethod;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
        $permission = Permission::where('name', 'create_sale')->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'create_sale']);
        $role->permissions()->attach($permission);
        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);

        $this->sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
            'status' => 'created',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);

        $this->digitalMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'GCASH',
            'name' => 'GCash',
            'type' => 'digital',
            'reference_required' => true,
            'status' => 'active',
        ]);
    }

    private function postSplit(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $this->sale->id]), $payload);
    }

    /** AC: Validation failure creates no payment records */
    public function test_validation_failure_creates_no_records(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50] // Underpayment
            ]
        ];

        $response = $this->postSplit($payload);
        $response->assertStatus(422);

        $this->assertEquals(0, SalePayment::count());
        $this->assertEquals('created', $this->sale->fresh()->status);
    }

    /** AC: Digital reference failure creates no records */
    public function test_missing_reference_creates_no_records(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->digitalMethod->id, 'amount' => 100, 'reference_number' => '']
            ]
        ];

        $response = $this->postSplit($payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.reference_number']);

        $this->assertEquals(0, SalePayment::count());
    }

    /** AC: Atomic rollback on partial failure */
    public function test_atomic_rollback_on_failure(): void
    {
        // Simulate a scenario where the first payment is valid but the second fails
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50],
                ['payment_method_id' => $this->digitalMethod->id, 'amount' => 50, 'reference_number' => ''] // Fails
            ]
        ];

        $response = $this->postSplit($payload);
        $response->assertStatus(422);

        $this->assertEquals(0, SalePayment::count(), 'All payment records must be rolled back on failure');
    }

    /** AC: Already paid sale failure */
    public function test_already_paid_failure_creates_no_extra_records(): void
    {
        $this->sale->update(['status' => 'paid']);
        $initialPayment = SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => 100
        ]);

        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]
            ]
        ];

        $response = $this->postSplit($payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);

        $this->assertEquals(1, SalePayment::count());
    }

    /** AC: Mutation silence check */
    public function test_failure_is_mutation_silent(): void
    {
        $payload = ['payments' => [['payment_method_id' => $this->cashMethod->id, 'amount' => 50]]];
        $this->postSplit($payload);

        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        if (\Schema::hasTable('accounting_outbox')) $this->assertEquals(0, \DB::table('accounting_outbox')->count());
    }
}
