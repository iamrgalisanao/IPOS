<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Models\ManualRefundRequest;
use App\Models\PosAdjustmentRequest;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoidRefundControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $supervisor;
    protected PaymentMethod $paymentMethod;

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
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        $this->cashier->assignToBranch($this->branch);
        $this->giveUserPermission($this->cashier, 'view_sale_details');

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'password' => Hash::make('superpassword123')
        ]);
        $this->supervisor->assignToBranch($this->branch);
        $this->giveUserPermission($this->supervisor, 'pos.void');
        $this->giveUserPermission($this->supervisor, 'pos.refund');

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash'
        ]);

        $this->actingAs($this->cashier);
    }

    protected function createSale(string $status = 'paid', string $paymentMethodName = 'Cash'): Sale
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'total' => 100.00,
            'status' => $status
        ]);

        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        
        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test Product',
            'quantity' => 2,
            'unit_price' => 50.00,
            'subtotal' => 100.00,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 100.00,
            'is_inventory_tracked' => false
        ]);

        $method = PaymentMethod::where('name', $paymentMethodName)->first() 
            ?: PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'name' => $paymentMethodName]);

        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 1000.00,
            'opened_at' => now()
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'shift_id' => $shift->id,
            'payment_method_id' => $method->id,
            'payment_type' => strtolower($paymentMethodName) === 'cash' ? 'cash' : 'card',
            'amount' => 100.00,
            'status' => 'recorded',
            'paid_at' => now()
        ]);

        return $sale;
    }

    public function test_void_sale_requires_idempotency_key()
    {
        $sale = $this->createSale();

        $response = $this->postWithContext(route('pos.sales.void', $sale->id), [
            'reason_code' => 'CANCELLATION'
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['code' => 'MISSING_IDEMPOTENCY_KEY']);
    }

    public function test_void_sale_requires_permission_or_supervisor_override()
    {
        $sale = $this->createSale();
        $idempotencyKey = (string) Str::uuid();

        // 1. Without supervisor credentials (cashier lacks direct permission)
        $response = $this->postWithContext(route('pos.sales.void', $sale->id), [
            'reason_code' => 'CANCELLATION'
        ], ['Idempotency-Key' => $idempotencyKey]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['code' => 'SUPERVISOR_AUTH_REQUIRED']);

        // 2. With invalid supervisor credentials
        $idempotencyKey2 = (string) Str::uuid();
        $response2 = $this->postWithContext(route('pos.sales.void', $sale->id), [
            'reason_code' => 'CANCELLATION',
            'supervisor_email' => $this->supervisor->email,
            'supervisor_password' => 'wrongpass'
        ], ['Idempotency-Key' => $idempotencyKey2]);

        $response2->assertStatus(403);
        $response2->assertJsonFragment(['code' => 'INVALID_SUPERVISOR_CREDENTIALS']);

        // 3. With valid supervisor credentials
        $idempotencyKey3 = (string) Str::uuid();
        $response3 = $this->postWithContext(route('pos.sales.void', $sale->id), [
            'reason_code' => 'CANCELLATION',
            'supervisor_email' => $this->supervisor->email,
            'supervisor_password' => 'superpassword123'
        ], ['Idempotency-Key' => $idempotencyKey3]);

        $response3->assertStatus(200);
        $this->assertEquals('voided', $sale->refresh()->status);
    }

    public function test_void_blocked_when_shift_is_closed()
    {
        $sale = $this->createSale();
        
        // Close the shift
        $payment = $sale->payments->first();
        $shift = $payment->shift;
        $shift->update(['status' => Shift::STATUS_CLOSED]);

        $idempotencyKey = (string) Str::uuid();
        $response = $this->postWithContext(route('pos.sales.void', $sale->id), [
            'reason_code' => 'CANCELLATION',
            'supervisor_email' => $this->supervisor->email,
            'supervisor_password' => 'superpassword123'
        ], ['Idempotency-Key' => $idempotencyKey]);

        $response->assertStatus(409);
        $response->assertJsonFragment(['code' => 'VOID_BLOCKED_SHIFT_CLOSED']);
    }

    public function test_idempotent_replays_return_identical_response()
    {
        $sale = $this->createSale();
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'reason_code' => 'CANCELLATION',
            'supervisor_email' => $this->supervisor->email,
            'supervisor_password' => 'superpassword123'
        ];

        // First call
        $response1 = $this->postWithContext(route('pos.sales.void', $sale->id), $payload, ['Idempotency-Key' => $idempotencyKey]);
        $response1->assertStatus(200);
        $content1 = $response1->json();

        // Second call (replay)
        $response2 = $this->postWithContext(route('pos.sales.void', $sale->id), $payload, ['Idempotency-Key' => $idempotencyKey]);
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache-Lookup', 'HIT - Idempotent response');
        
        $this->assertEquals($content1['data']['void_id'], $response2->json()['data']['void_id']);
    }

    public function test_refund_electronic_payment_on_closed_shift_creates_manual_refund_request()
    {
        $sale = $this->createSale('paid', 'Card');
        
        // Close the shift
        $payment = $sale->payments->first();
        $shift = $payment->shift;
        $shift->update(['status' => Shift::STATUS_CLOSED]);

        $item = $sale->items->first();
        $idempotencyKey = (string) Str::uuid();

        $response = $this->postWithContext(route('pos.sales.refund', $sale->id), [
            'items' => [
                ['sale_item_id' => $item->id, 'quantity' => 1]
            ],
            'payout_method' => 'electronic',
            'customer_refund_channel' => 'bank_transfer',
            'customer_reference_details' => 'PH Bank Acc 12345',
            'supervisor_email' => $this->supervisor->email,
            'supervisor_password' => 'superpassword123'
        ], ['Idempotency-Key' => $idempotencyKey]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['code' => 'MANUAL_REFUND_REQUESTED']);

        $this->assertDatabaseHas('manual_refund_requests', [
            'sale_id' => $sale->id,
            'status' => 'pending_approval',
            'requested_refund_amount' => 50.00
        ]);
    }

    protected function postWithContext(string $route, array $payload = [], array $headers = [])
    {
        $defaultHeaders = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ];

        return $this->postJson($route, $payload, array_merge($defaultHeaders, $headers));
    }

    protected function giveUserPermission(User $user, string $permissionName): void
    {
        $permission = \App\Models\Permission::firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => $permissionName
        ]);

        $role = \App\Models\Role::firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => 'Test Role for ' . $permissionName
        ]);

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->assignRole($role);
    }
}
