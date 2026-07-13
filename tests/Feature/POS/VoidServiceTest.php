<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalePromotion;
use App\Models\SalePromotionLine;
use App\Models\SaleVoid;
use App\Models\PaymentReversal;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Services\POS\VoidService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VoidServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected VoidService $voidService;

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

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        $this->actingAs($this->user);

        $this->voidService = app(VoidService::class);
    }

    /**
     * Helper to create a paid sale with multiple items and payments.
     */
    protected function createComplexPaidSale(float $total = 300): Sale
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'total' => $total,
            'status' => 'paid'
        ]);

        // 2 Items
        foreach ([1, 2] as $i) {
            $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'is_inventory_tracked' => true]);
            BranchInventory::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'current_stock' => 10,
                'status' => 'active'
            ]);
            SaleItem::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => "Product $i",
                'quantity' => 1,
                'unit_price' => $total / 2,
                'subtotal' => $total / 2,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'line_total' => $total / 2,
                'is_inventory_tracked' => true
            ]);
        }

        // 2 Payments (Split)
        $method = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);
        foreach ([1, 2] as $j) {
            SalePayment::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'sale_id' => $sale->id,
                'payment_method_id' => $method->id,
                'payment_type' => $method->type,
                'amount' => $total / 2,
                'status' => 'recorded'
            ]);
        }

        app(InventoryService::class)->deductFromSale($sale);

        return $sale->refresh();
    }

    protected function attachPromotionSnapshot(Sale $sale, int $discountCentavos = 3000): SalePromotion
    {
        $item = $sale->items()->firstOrFail();

        DB::table('sales')->where('id', $sale->id)->update([
            'commercial_discount_total' => number_format($discountCentavos / 100, 4, '.', ''),
            'discount_total' => number_format($discountCentavos / 100, 4, '.', ''),
        ]);

        DB::table('sale_items')->where('id', $item->id)->update([
            'promotion_discount_centavos' => $discountCentavos,
            'promotion_adjusted_unit_price_centavos' => max(0, ((int) round(((float) $item->unit_price) * 100)) - $discountCentavos),
        ]);

        $promotion = Promotion::create([
            'name' => 'Void Promo',
            'rule_type' => 'discount_tier',
            'priority' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $rule = PromotionRule::create([
            'promotion_id' => $promotion->id,
            'condition_type' => 'minimum_spend',
            'conditions' => ['minimum_amount_centavos' => 10000],
            'reward_type' => 'amount_off',
            'rewards' => ['amount_centavos' => $discountCentavos],
            'is_active' => true,
        ]);

        $salePromotion = SalePromotion::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'promotion_id' => $promotion->id,
            'promotion_rule_id' => $rule->id,
            'promotion_name' => 'Void Promo',
            'rule_type' => 'discount_tier',
            'condition_type' => 'minimum_spend',
            'reward_type' => 'amount_off',
            'priority' => 1,
            'stackable' => false,
            'base_amount_centavos' => (int) round(((float) $item->subtotal) * 100),
            'discount_amount_centavos' => $discountCentavos,
            'rule_snapshot_json' => ['name' => 'Void Promo'],
            'condition_snapshot_json' => ['minimum_amount_centavos' => 10000],
            'reward_snapshot_json' => ['amount_centavos' => $discountCentavos],
            'calculation_snapshot_json' => ['engine_version' => 'EPIC37_V1'],
            'promotion_rules_version_hash' => str_repeat('d', 64),
        ]);

        SalePromotionLine::create([
            'sale_promotion_id' => $salePromotion->id,
            'sale_item_id' => $item->id,
            'product_id' => $item->product_id,
            'role' => 'discounted',
            'quantity_applied' => $item->quantity,
            'original_amount_centavos' => (int) round(((float) $item->subtotal) * 100),
            'discount_amount_centavos' => $discountCentavos,
            'final_amount_centavos' => max(0, (int) round(((float) $item->subtotal) * 100) - $discountCentavos),
        ]);

        return $salePromotion;
    }

    /** 1-17, 22-30: Full Void Success and Integrity */
    public function test_full_sale_void_integrity(): void
    {
        $sale = $this->createComplexPaidSale(300);
        $originalPayments = $sale->payments->pluck('id')->toArray();
        $originalMovements = InventoryMovement::where('source_type', 'sale')->pluck('id')->toArray();

        $void = $this->voidService->void($sale, 'void_all', 'Full cancellation');

        // 1, 5, 22: Sale status and record
        $this->assertEquals('voided', $sale->refresh()->status);
        $this->assertDatabaseHas('sale_voids', ['sale_id' => $sale->id, 'reason_code' => 'void_all']);
        $this->assertEquals(300, (float) $sale->total); // 22: Totals unchanged

        // 7, 8, 9, 10: Payments
        $reversals = PaymentReversal::where('sale_id', $sale->id)->get();
        $this->assertCount(2, $reversals); // 7: Multiple payments reversed
        $this->assertEquals(300, $reversals->sum('amount')); // 8: Sum equals original
        foreach ($reversals as $rev) {
            $this->assertEquals($this->tenant->id, $rev->tenant_id); // 9: Tenant scoped
            $this->assertEquals($this->branch->id, $rev->branch_id); // 10: Branch scoped
        }

        // 12, 13, 14, 15: Inventory
        $revMovements = InventoryMovement::where('movement_type', 'void_reversal')->get();
        $this->assertCount(2, $revMovements); // 12: Multiple deductions reversed
        foreach ($revMovements as $revM) {
            $this->assertEquals('void_reversal', $revM->movement_type); // 13: Correct type
            $this->assertContains($revM->original_movement_id, $originalMovements); // 14: Correct linkage
        }
        $this->assertEquals(10, (float) BranchInventory::first()->current_stock); // 15: Stock restored

        // 23, 24, 25: Originals unchanged
        $this->assertCount(2, SaleItem::where('sale_id', $sale->id)->get()); // 23
        foreach ($originalPayments as $pId) {
            $payment = SalePayment::find($pId);
            $this->assertEquals(150, (float) $payment->amount); // 24
        }
        foreach ($originalMovements as $mId) {
            $movement = InventoryMovement::find($mId);
            $this->assertEquals(-1, (float) $movement->quantity_change); // 25
        }

        // 26, 27: Audit
        $audit = AuditLog::where('action', 'sale_voided')->where('auditable_id', $sale->id)->first();
        $this->assertNotNull($audit); // 26
        $this->assertEquals($this->tenant->id, $audit->tenant_id); // 27
        $this->assertEquals($this->branch->id, $audit->branch_id);
        $this->assertEquals($this->user->id, $audit->actor_user_id);

        // 28, 29: Accounting event is captured and no refund is created.
        $this->assertDatabaseHas('accounting_outbox', [
            'event_type' => 'sale_voided',
            'source_type' => 'sale_void',
            'source_id' => $void->id,
        ]);
        $this->assertDatabaseEmpty('sale_refunds'); // 29
    }

    public function test_void_reverses_commercial_promotion_totals_without_deleting_snapshot(): void
    {
        $sale = $this->createComplexPaidSale(300);
        $salePromotion = $this->attachPromotionSnapshot($sale, 3000);

        $this->voidService->void($sale->refresh(), 'void_promo', 'Promotion reversal');

        $sale->refresh();
        $this->assertEquals(0.00, (float) $sale->commercial_discount_total);
        $this->assertEquals(0, (int) DB::table('sale_items')->where('sale_id', $sale->id)->sum('promotion_discount_centavos'));
        $this->assertDatabaseHas('sale_promotions', [
            'id' => $salePromotion->id,
            'discount_amount_centavos' => 3000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'commercial_promotion_reversed_void',
            'auditable_id' => $sale->id,
        ]);
    }

    /** 2, 3, 4, 9: Status Guards */
    public function test_void_status_guards(): void
    {
        // 2: Created
        $sale1 = Sale::factory()->create(['status' => 'created']);
        $this->expectException(\RuntimeException::class);
        $this->voidService->void($sale1, 'err');

        // 3: Already Voided (Idempotency)
        $sale2 = $this->createComplexPaidSale();
        $this->voidService->void($sale2, 'void1');
        try {
            $this->voidService->void($sale2, 'void2');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already been voided', $e->getMessage());
        }
        $this->assertDatabaseCount('sale_voids', 1); // 6: Only one record
        $this->assertDatabaseCount('payment_reversals', 2); // 17: No duplicate reversals
        $this->assertDatabaseCount('inventory_movements', 4); // 16: 2 deduction + 2 reversal
    }

    /** 11, 24, 25: Immutability */
    public function test_reversal_records_are_immutable(): void
    {
        $sale = $this->createComplexPaidSale();
        $void = $this->voidService->void($sale, 'void');
        $reversal = PaymentReversal::first();

        // 11: PaymentReversal
        try { $reversal->update(['amount' => 0]); } catch (\Exception $e) {}
        $this->assertEquals(150, (float) $reversal->refresh()->amount);

        // SaleVoid
        try { $void->delete(); } catch (\Exception $e) {}
        $this->assertDatabaseHas('sale_voids', ['id' => $void->id]);
    }

    /** 18, 19: Isolation */
    public function test_tenant_and_branch_isolation(): void
    {
        $sale = $this->createComplexPaidSale();
        
        // 18: Tenant Isolation
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantB);
        
        try {
            $this->voidService->void($sale, 'cross_tenant');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }

        // 19: Branch Isolation
        app(TenantContext::class)->setTenant($this->tenant);
        $branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($branchB);

        try {
            $this->voidService->void($sale, 'cross_branch');
            $this->fail('Expected cross-branch void to be rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
    }

    /** 20, 21: Atomicity */
    public function test_void_atomicity_on_failure(): void
    {
        $sale = $this->createComplexPaidSale();
        
        BranchInventory::where('branch_id', $sale->branch_id)->first()->delete();

        try {
            $this->voidService->void($sale, 'atomic_fail');
        } catch (\Exception $e) {}

        // Assert everything rolled back
        $this->assertEquals('paid', $sale->refresh()->status);
        $this->assertDatabaseMissing('sale_voids', ['sale_id' => $sale->id]);
        $this->assertDatabaseMissing('payment_reversals', ['sale_id' => $sale->id]);
        $this->assertCount(2, InventoryMovement::all()); // Only original 2 deductions
    }
}
