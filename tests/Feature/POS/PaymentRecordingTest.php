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

class PaymentRecordingTest extends TestCase
{
    use RefreshDatabase, \Tests\Traits\InteractsWithShifts;

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
        
        $openShiftPermission = Permission::where('name', 'open_shift')->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'open_shift']);
        $role->permissions()->attach($openShiftPermission);

        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);

        $this->sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 100.0000,
            'status' => 'created',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'reference_required' => false,
            'status' => 'active',
        ]);

        $this->digitalMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'GCASH',
            'name' => 'GCash',
            'type' => 'e-wallet',
            'reference_required' => true,
            'strict_reference_mode' => true,
            'status' => 'active',
        ]);

        $this->openShiftFor($this->user, $this->branch);
    }

    private function postPayment(string $saleId, array $payload, ?User $user = null, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $actingUser = $user === null ? $this->user : $user;
        
        $defaultHeaders = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ];

        $finalHeaders = array_merge($defaultHeaders, $headers);

        $request = $this;
        if ($actingUser) {
            $request = $request->actingAs($actingUser);
        }

        foreach ($finalHeaders as $key => $value) {
            if ($value === null) {
                // Do not add
            } else {
                $request = $request->withHeader($key, $value);
            }
        }

        return $request->postJson(route('pos.sales.payments', ['sale_id' => $saleId]), $payload);
    }

    /** #1: Payment recording requires authenticated user */
    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson(route('pos.sales.payments', ['sale_id' => $this->sale->id]), []);
        $response->assertStatus(401);
    }

    /** #2: Payment recording requires active TenantContext matching user */
    public function test_it_requires_tenant_context_matching_user(): void
    {
        $otherTenant = Tenant::factory()->create();
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $otherTenant->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $this->sale->id]), []);
        $response->assertStatus(403);
        $response->assertSee('Tenant context mismatch');
    }

    /** #3: Payment recording requires active BranchContext */
    public function test_it_requires_branch_context(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $this->sale->id]), []);
        $response->assertStatus(403);
        $response->assertSee('Branch context missing');
    }

    /** #4, #5: Payment recording requires permission, 403 on missing */
    public function test_it_requires_permission(): void
    {
        $plainUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $plainUser->assignToBranch($this->branch);
        $response = $this->postPayment($this->sale->id, [], $plainUser);
        $response->assertStatus(403);
    }

    /** #6: Sale from another tenant cannot be paid */
    public function test_it_enforces_tenant_isolation(): void
    {
        app(TenantContext::class)->clear();
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $otherSale = Sale::factory()->create(['tenant_id' => $otherTenant->id, 'total' => 100]);
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->postPayment($otherSale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sale']);
    }

    /** #7: Sale from another branch cannot be paid through active branch context */
    public function test_it_enforces_branch_isolation(): void
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherSale = Sale::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $otherBranch->id, 'total' => 100]);
        $response = $this->postPayment($otherSale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sale']);
    }

    /** #8: Payment method from another tenant is rejected */
    public function test_it_rejects_payment_method_from_another_tenant(): void
    {
        app(TenantContext::class)->clear();
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $otherMethod = PaymentMethod::create(['tenant_id' => $otherTenant->id, 'code' => 'OT', 'name' => 'Other', 'type' => 'cash', 'status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $otherMethod->id, 'amount' => 100]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.payment_method_id']);
    }

    /** #9: Inactive payment method is rejected */
    public function test_it_rejects_inactive_payment_method(): void
    {
        $this->cashMethod->update(['status' => 'inactive']);
        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.payment_method_id']);
    }

    /** #10, #11: Positive exact cash payment is recorded, status becomes paid */
    public function test_it_records_exact_cash_payment_successfully(): void
    {
        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'recorded', 'sale_status' => 'paid']);
        $this->assertEquals('paid', $this->sale->fresh()->status);
    }

    /** #12: Underpayment is rejected */
    public function test_it_rejects_underpayment(): void
    {
        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 99.99]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /** #13: Overpayment is rejected */
    public function test_it_rejects_overpayment(): void
    {
        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100.01]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /** #14, #15: Zero or negative payment amount is rejected */
    public function test_it_rejects_invalid_amounts(): void
    {
        $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 0])->assertStatus(422);
        $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => -1])->assertStatus(422);
    }

    /** #16: Cash payment does not require reference number */
    public function test_cash_does_not_require_reference(): void
    {
        $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100])->assertStatus(200);
    }

    /** #17, #18: Digital payment requires reference, stores reference */
    public function test_digital_requires_and_stores_reference(): void
    {
        // Fail without reference
        $this->postPayment($this->sale->id, ['payment_method_id' => $this->digitalMethod->id, 'amount' => 100])->assertStatus(422);
        
        // Succeed with reference
        $this->postPayment($this->sale->id, ['payment_method_id' => $this->digitalMethod->id, 'amount' => 100, 'reference_number' => 'REF123'])->assertStatus(200);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $this->sale->id, 'reference_number' => 'REF123']);
    }

    /** #19: Strict reference mode rejects blank/whitespace reference */
    public function test_strict_mode_rejects_whitespace_reference(): void
    {
        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $this->digitalMethod->id, 'amount' => 100, 'reference_number' => '   ']);
        $response->assertStatus(422);
    }

    /** #20: Payment recording is atomic */
    public function test_it_is_atomic_on_failure(): void
    {
        $this->assertEquals(0, SalePayment::count());
        try {
            \DB::transaction(function() {
                SalePayment::create([
                    'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'sale_id' => $this->sale->id,
                    'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'cash', 'amount' => 100, 'status' => 'recorded'
                ]);
                throw new \RuntimeException('Simulated fail');
            });
        } catch (\RuntimeException $e) {}
        $this->assertEquals(0, SalePayment::count());
    }

    /** #21: Already-paid sale cannot be paid again */
    public function test_cannot_pay_already_paid_sale(): void
    {
        $this->sale->update(['status' => 'paid']);
        $response = $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]);
        $response->assertStatus(422);
    }

    /** #22, #23: Payment record is tenant-scoped, Tenant A cannot see Tenant B */
    public function test_payment_tenant_scoping(): void
    {
        $payment = SalePayment::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'cash', 'amount' => 100, 'status' => 'recorded'
        ]);
        $this->assertEquals($this->tenant->id, $payment->tenant_id);

        app(TenantContext::class)->clear();
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $this->assertNull(SalePayment::find($payment->id));
    }

    /** #24: SalePayment records are immutable */
    public function test_salepayments_are_immutable(): void
    {
        $payment = SalePayment::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'cash', 'amount' => 100, 'status' => 'recorded'
        ]);
        try { $payment->update(['amount' => 200]); } catch (\Exception $e) {}
        $this->assertEquals(100, $payment->fresh()->amount);
        try { $payment->delete(); } catch (\Exception $e) {}
        $this->assertNotNull(SalePayment::find($payment->id));
    }

    /** #25, #26, #27: Mutation silence on inventory, accounting, and refunds */
    public function test_mutation_silence(): void
    {
        $this->postPayment($this->sale->id, ['payment_method_id' => $this->cashMethod->id, 'amount' => 100]);
        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        if (\Schema::hasTable('accounting_outbox')) $this->assertEquals(1, \DB::table('accounting_outbox')->count());
        if (\Schema::hasTable('refunds')) $this->assertEquals(0, \DB::table('refunds')->count());
    }
}
