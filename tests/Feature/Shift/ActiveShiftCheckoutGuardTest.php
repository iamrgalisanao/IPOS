<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\PaymentRecordingService;
use App\Services\Shift\ShiftService;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveShiftCheckoutGuardTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentRecordingService $paymentService;
    protected ShiftService $shiftService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignToBranch($this->branch);
        $this->cashier->assignRole(\App\Models\Role::where('name', 'Cashier')->first());

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'cash',
            'status' => 'active'
        ]);

        $this->paymentService = app(PaymentRecordingService::class);
        $this->shiftService = app(ShiftService::class);
    }

    protected function createSale(string $total = '100.00'): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sale_number' => 'SALE-' . uniqid(),
            'status' => 'confirmed',
            'subtotal' => $total,
            'total' => $total,
            'tax_total' => '0.00',
            'discount_total' => '0.00',
        ]);

        return $sale;
    }

    public function test_checkout_requires_active_shift(): void
    {
        $sale = $this->createSale();

        // No shift opened yet
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active shift found');

        $this->paymentService->record($sale->id, [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.00'
        ], $this->cashier);
    }

    public function test_checkout_attaches_payment_to_active_shift(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, $this->branch, '1000.00', $this->cashier);
        $sale = $this->createSale();

        $payment = $this->paymentService->record($sale->id, [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.00'
        ], $this->cashier);

        $this->assertEquals($shift->id, $payment->shift_id);
    }

    public function test_checkout_fails_if_shift_belongs_to_another_cashier(): void
    {
        $otherCashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCashier->assignToBranch($this->branch);
        $otherCashier->assignRole(\App\Models\Role::where('name', 'Cashier')->first());

        // Other cashier opens a shift
        $this->shiftService->openShift($otherCashier, $this->branch, '1000.00', $otherCashier);
        
        $sale = $this->createSale();

        // Our cashier tries to checkout
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active shift found');

        $this->paymentService->record($sale->id, [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.00'
        ], $this->cashier);
    }

    public function test_checkout_fails_if_shift_is_in_another_branch(): void
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignToBranch($otherBranch);

        // Cashier opens shift in other branch
        $this->shiftService->openShift($this->cashier, $otherBranch, '1000.00', $this->cashier);
        
        // Sale is in original branch
        $sale = $this->createSale();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active shift found');

        $this->paymentService->record($sale->id, [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.00'
        ], $this->cashier);
    }

    public function test_failed_shift_guard_preserves_atomicity(): void
    {
        $sale = $this->createSale();
        
        $saleCount = \DB::table('sales')->count();
        $paymentCount = \DB::table('sale_payments')->count();
        $outboxCount = \DB::table('accounting_outbox')->count();

        try {
            $this->paymentService->record($sale->id, [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '100.00'
            ], $this->cashier);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No active shift found', $e->getMessage());
        }

        // Sale should still be 'confirmed' (not 'paid')
        $this->assertEquals('confirmed', $sale->fresh()->status);
        
        // No new records created
        $this->assertEquals($paymentCount, \DB::table('sale_payments')->count());
        $this->assertEquals($outboxCount, \DB::table('accounting_outbox')->count());
    }

    public function test_split_payment_attaches_all_parts_to_active_shift(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, $this->branch, '1000.00', $this->cashier);
        $sale = $this->createSale('150.00');

        $payments = $this->paymentService->recordSplit($sale->id, [
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '100.00'],
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '50.00'],
        ], $this->cashier);

        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            $this->assertEquals($shift->id, $payment->shift_id);
        }
    }
}
