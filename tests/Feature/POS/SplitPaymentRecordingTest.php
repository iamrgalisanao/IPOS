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

class SplitPaymentRecordingTest extends TestCase
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
            'total' => 112.0000,
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

    private function postSplitPayment(string $saleId, array $payload, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user ?? $this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $saleId]), $payload);
    }

    /** AC: Split payment requires authenticated user */
    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson(route('pos.sales.payments.split', ['sale_id' => $this->sale->id]), []);
        $response->assertStatus(401);
    }

    /** AC: Split payment requires permission */
    public function test_it_requires_permission(): void
    {
        $plainUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $plainUser->assignToBranch($this->branch);
        $response = $this->postSplitPayment($this->sale->id, [], $plainUser);
        $response->assertStatus(403);
    }

    /** AC: Two valid payment entries are recorded successfully */
    public function test_it_records_two_payment_entries_successfully(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100],
                ['payment_method_id' => $this->digitalMethod->id, 'amount' => 12, 'reference_number' => 'REF123']
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'recorded',
            'sale_status' => 'paid',
            'payment_count' => 2,
            'amount_paid' => '112.0000',
        ]);

        $this->assertEquals(2, SalePayment::where('sale_id', $this->sale->id)->count());
        $this->assertEquals('paid', $this->sale->fresh()->status);
    }

    /** AC: Three valid payment entries are recorded */
    public function test_it_records_three_payment_entries_successfully(): void
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 150.0000,
            'status' => 'created',
        ]);

        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50],
                ['payment_method_id' => $this->digitalMethod->id, 'amount' => 50, 'reference_number' => 'REF3']
            ]
        ];

        $response = $this->postSplitPayment($sale->id, $payload);

        $response->assertStatus(200);
        $response->assertJson(['payment_count' => 3, 'amount_paid' => '150.0000']);
    }

    /** AC: Sum of payments must equal sale total (Underpayment rejection) */
    public function test_it_rejects_underpayment(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 10]
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /** AC: Sum of payments must equal sale total (Overpayment rejection) */
    public function test_it_rejects_overpayment(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 20]
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /** AC: Zero payment amount in any entry is rejected */
    public function test_it_rejects_zero_amount_entry(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 112],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 0]
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.1.amount']);
    }

    /** AC: Negative payment amount in any entry is rejected */
    public function test_it_rejects_negative_amount_entry(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 122],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => -10]
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.1.amount']);
    }

    /** AC: Digital payment with reference_required = true requires reference number */
    public function test_it_requires_reference_for_digital_split_entry(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->digitalMethod->id, 'amount' => 112, 'reference_number' => '']
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.reference_number']);
    }

    /** AC: All payment entries are recorded atomically (Rollback proof) */
    public function test_it_rolls_back_everything_if_one_entry_fails(): void
    {
        $this->assertEquals(0, SalePayment::count());

        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 56],
                ['payment_method_id' => $this->digitalMethod->id, 'amount' => 56, 'reference_number' => ''] // Missing REF
            ]
        ];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);

        $this->assertEquals(0, SalePayment::count());
        $this->assertEquals('created', $this->sale->fresh()->status);
    }

    /** AC: Already-paid sale cannot be paid again */
    public function test_cannot_pay_already_paid_sale(): void
    {
        $this->sale->update(['status' => 'paid']);
        $payload = ['payments' => [['payment_method_id' => $this->cashMethod->id, 'amount' => 112]]];

        $response = $this->postSplitPayment($this->sale->id, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    /** AC: SalePayment immutability remains enforced */
    public function test_salepayments_are_immutable(): void
    {
        $payload = ['payments' => [['payment_method_id' => $this->cashMethod->id, 'amount' => 112]]];
        $this->postSplitPayment($this->sale->id, $payload);
        
        $payment = SalePayment::first();
        $this->expectException(\Exception::class);
        $payment->update(['amount' => 200]);
    }

    /** AC: Payment finalization records accounting and leaves inventory unchanged when sale has no tracked items */
    public function test_payment_finalization_side_effects(): void
    {
        $payload = ['payments' => [['payment_method_id' => $this->cashMethod->id, 'amount' => 112]]];
        $response = $this->postSplitPayment($this->sale->id, $payload);

        $response->assertStatus(200);

        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        if (\Schema::hasTable('accounting_outbox')) {
            $this->assertDatabaseHas('accounting_outbox', [
                'event_type' => 'sale_paid',
                'source_type' => 'sale',
                'source_id' => $this->sale->id,
            ]);
        }
    }

    /** Story 5.1 exact single-payment behavior remains green */
    public function test_single_payment_behavior_remains_green(): void
    {
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 112];
        
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $this->sale->id]), $payload);

        $response->assertStatus(200);
        $this->assertEquals('paid', $this->sale->fresh()->status);
    }
}
