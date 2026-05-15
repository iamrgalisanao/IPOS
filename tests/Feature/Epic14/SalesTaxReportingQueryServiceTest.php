<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\PaymentReversal;
use App\Models\SaleRefund;
use App\Models\SaleStatutoryDiscount;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Tax\SalesTaxReportingQueryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesTaxReportingQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const EXPECTED_SUMMARY_KEYS = [
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
    protected User $user;
    protected PaymentMethod $cash;
    protected SalesTaxReportingQueryService $service;

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
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cash = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($this->otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->otherTenant->id, 'status' => 'active', 'name' => 'Other Branch']);
        User::factory()->create(['tenant_id' => $this->otherTenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->service = app(SalesTaxReportingQueryService::class);

        $this->seedReportingData($otherBranch->id);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_tenant_scoped_tax_summary_query_returns_expected_totals(): void
    {
        $summary = $this->service->summarize($this->tenant->id, '2026-05-12 00:00:00', '2026-05-12 23:59:59');

        $this->assertSame(self::EXPECTED_SUMMARY_KEYS, array_keys($summary));
        $this->assertSame($this->tenant->id, $summary['tenant_id']);
        $this->assertNull($summary['branch_id']);
        $this->assertSame('350.0000', $summary['gross_sales']);
        $this->assertSame('154.0000', $summary['net_sales']);
        $this->assertSame('250.0000', $summary['vatable_sales']);
        $this->assertSame('40.0000', $summary['vat_exempt_sales']);
        $this->assertSame('30.0000', $summary['zero_rated_sales']);
        $this->assertSame('20.0000', $summary['non_vat_sales']);
        $this->assertSame('36.0000', $summary['vat_amount']);
        $this->assertSame('15.0000', $summary['statutory_discount_amount']);
        $this->assertSame('6.0000', $summary['regular_discount_amount']);
        $this->assertSame('25.0000', $summary['refund_adjustment_amount']);
        $this->assertSame('150.0000', $summary['void_adjustment_amount']);
        $this->assertSame('90.0000', $summary['reversal_adjustment_amount']);
        $this->assertSame('265.0000', $summary['net_adjustment_amount']);
        $this->assertSame(1, $summary['refund_count']);
        $this->assertSame(2, $summary['void_count']);
        $this->assertSame(3, $summary['reversal_count']);
        $this->assertSame(1, $summary['reviewed_period_count']);
        $this->assertSame(1, $summary['locked_period_count']);
        $this->assertTrue($summary['has_reviewed_period']);
        $this->assertTrue($summary['has_locked_period']);
        $this->assertSame(3, $summary['transaction_count']);
    }

    public function test_empty_result_returns_complete_zeroed_contract(): void
    {
        $summary = $this->service->summarize($this->tenant->id, '2026-05-14 00:00:00', '2026-05-14 23:59:59');

        $this->assertSame(self::EXPECTED_SUMMARY_KEYS, array_keys($summary));
        $this->assertSame($this->tenant->id, $summary['tenant_id']);
        $this->assertNull($summary['branch_id']);
        $this->assertSame('2026-05-14 00:00:00', $summary['date_from']);
        $this->assertSame('2026-05-14 23:59:59', $summary['date_to']);
        $this->assertSame('0.0000', $summary['gross_sales']);
        $this->assertSame('0.0000', $summary['net_sales']);
        $this->assertSame('0.0000', $summary['vatable_sales']);
        $this->assertSame('0.0000', $summary['vat_exempt_sales']);
        $this->assertSame('0.0000', $summary['zero_rated_sales']);
        $this->assertSame('0.0000', $summary['non_vat_sales']);
        $this->assertSame('0.0000', $summary['vat_amount']);
        $this->assertSame('0.0000', $summary['statutory_discount_amount']);
        $this->assertSame('0.0000', $summary['regular_discount_amount']);
        $this->assertSame('0.0000', $summary['refund_adjustment_amount']);
        $this->assertSame('0.0000', $summary['void_adjustment_amount']);
        $this->assertSame('0.0000', $summary['reversal_adjustment_amount']);
        $this->assertSame('0.0000', $summary['net_adjustment_amount']);
        $this->assertSame(0, $summary['refund_count']);
        $this->assertSame(0, $summary['void_count']);
        $this->assertSame(0, $summary['reversal_count']);
        $this->assertSame(0, $summary['reviewed_period_count']);
        $this->assertSame(0, $summary['locked_period_count']);
        $this->assertFalse($summary['has_reviewed_period']);
        $this->assertFalse($summary['has_locked_period']);
        $this->assertSame(0, $summary['transaction_count']);
    }

    public function test_branch_scoped_tax_summary_query_returns_only_branch_data(): void
    {
        $summary = $this->service->summarize($this->tenant->id, '2026-05-12 00:00:00', '2026-05-12 23:59:59', $this->branchA->id);

        $this->assertSame($this->branchA->id, $summary['branch_id']);
        $this->assertSame('300.0000', $summary['gross_sales']);
        $this->assertSame('154.0000', $summary['net_sales']);
        $this->assertSame('250.0000', $summary['vatable_sales']);
        $this->assertSame('40.0000', $summary['vat_exempt_sales']);
        $this->assertSame('0.0000', $summary['zero_rated_sales']);
        $this->assertSame('0.0000', $summary['non_vat_sales']);
        $this->assertSame('36.0000', $summary['vat_amount']);
        $this->assertSame('15.0000', $summary['statutory_discount_amount']);
        $this->assertSame('6.0000', $summary['regular_discount_amount']);
        $this->assertSame('25.0000', $summary['refund_adjustment_amount']);
        $this->assertSame('100.0000', $summary['void_adjustment_amount']);
        $this->assertSame('65.0000', $summary['reversal_adjustment_amount']);
        $this->assertSame('190.0000', $summary['net_adjustment_amount']);
        $this->assertSame(1, $summary['refund_count']);
        $this->assertSame(1, $summary['void_count']);
        $this->assertSame(2, $summary['reversal_count']);
        $this->assertSame(1, $summary['reviewed_period_count']);
        $this->assertSame(1, $summary['locked_period_count']);
        $this->assertTrue($summary['has_reviewed_period']);
        $this->assertTrue($summary['has_locked_period']);
        $this->assertSame(2, $summary['transaction_count']);
    }

    public function test_date_range_filtering_excludes_records_outside_window(): void
    {
        $summary = $this->service->summarize($this->tenant->id, '2026-05-13 00:00:00', '2026-05-13 23:59:59');

        $this->assertSame('10.0000', $summary['gross_sales']);
        $this->assertSame('0.0000', $summary['statutory_discount_amount']);
        $this->assertSame('0.0000', $summary['net_adjustment_amount']);
        $this->assertFalse($summary['has_reviewed_period']);
        $this->assertFalse($summary['has_locked_period']);
        $this->assertSame(1, $summary['transaction_count']);
    }

    public function test_date_range_boundaries_are_inclusive_and_adjustments_stay_separate_from_base_totals(): void
    {
        $summary = $this->service->summarize($this->tenant->id, '2026-05-12 09:00:00', '2026-05-12 10:00:00');

        $this->assertSame('300.0000', $summary['gross_sales']);
        $this->assertSame('279.0000', $summary['net_sales']);
        $this->assertSame('0.0000', $summary['refund_adjustment_amount']);
        $this->assertSame('0.0000', $summary['void_adjustment_amount']);
        $this->assertSame('0.0000', $summary['reversal_adjustment_amount']);
        $this->assertSame('0.0000', $summary['net_adjustment_amount']);
        $this->assertSame(0, $summary['refund_count']);
        $this->assertSame(0, $summary['void_count']);
        $this->assertSame(0, $summary['reversal_count']);
        $this->assertTrue($summary['has_reviewed_period']);
        $this->assertTrue($summary['has_locked_period']);
        $this->assertSame(2, $summary['transaction_count']);
    }

    public function test_query_is_read_only_and_does_not_mutate_records(): void
    {
        $salesBefore = Sale::count();
        $saleItemsBefore = SaleItem::count();
        $discountsBefore = SaleStatutoryDiscount::count();
        $refundsBefore = SaleRefund::count();
        $voidsBefore = SaleVoid::count();
        $reversalsBefore = PaymentReversal::count();
        $periodsBefore = SettlementPeriod::count();

        $this->service->summarize($this->tenant->id, '2026-05-12 00:00:00', '2026-05-12 23:59:59');

        $this->assertSame($salesBefore, Sale::count());
        $this->assertSame($saleItemsBefore, SaleItem::count());
        $this->assertSame($discountsBefore, SaleStatutoryDiscount::count());
        $this->assertSame($refundsBefore, SaleRefund::count());
        $this->assertSame($voidsBefore, SaleVoid::count());
        $this->assertSame($reversalsBefore, PaymentReversal::count());
        $this->assertSame($periodsBefore, SettlementPeriod::count());
    }

    protected function seedReportingData(string $otherTenantBranchId): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $saleA = $this->createSale($this->branchA->id, 'SALE-A', '100.0000', '100.0000', '12.0000', '4.0000', '2026-05-12 09:00:00');
        $saleB = $this->createSale($this->branchA->id, 'SALE-B', '200.0000', '200.0000', '24.0000', '2.0000', '2026-05-12 10:00:00');
        $saleC = $this->createSale($this->branchB->id, 'SALE-C', '50.0000', '50.0000', '0.0000', '0.0000', '2026-05-12 11:00:00');
        $this->createSale($this->branchB->id, 'SALE-D', '10.0000', '10.0000', '0.0000', '0.0000', '2026-05-13 09:00:00');

        $paymentA = $this->createSalePayment($saleA->id, $this->branchA->id, '100.0000', '2026-05-12 09:00:00');
        $paymentB = $this->createSalePayment($saleB->id, $this->branchA->id, '200.0000', '2026-05-12 10:00:00');
        $paymentC = $this->createSalePayment($saleC->id, $this->branchB->id, '50.0000', '2026-05-12 11:00:00');

        $this->createSaleItem($saleA->id, $this->branchA->id, 'vatable', '100.0000', '100.0000', '0.0000', '0.0000', '0.0000');
        $this->createSaleItem($saleB->id, $this->branchA->id, 'vatable', '150.0000', '150.0000', '0.0000', '0.0000', '0.0000');
        $this->createSaleItem($saleB->id, $this->branchA->id, 'vat_exempt', '40.0000', '0.0000', '40.0000', '0.0000', '0.0000');
        $this->createSaleItem($saleC->id, $this->branchB->id, 'zero_rated', '30.0000', '0.0000', '0.0000', '30.0000', '0.0000');
        $this->createSaleItem($saleC->id, $this->branchB->id, 'non_vat', '20.0000', '0.0000', '0.0000', '0.0000', '20.0000');

        $this->createDiscount($saleA->id, null, '10.0000', '2026-05-12 09:10:00');
        $this->createDiscount($saleB->id, null, '5.0000', '2026-05-12 10:10:00');

        SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $saleB->id,
            'refund_number' => 'REF-001',
            'reason_code' => 'return',
            'reason_notes' => 'Return',
            'refund_total' => '25.0000',
            'refunded_by' => $this->user->id,
            'refunded_at' => '2026-05-12 12:00:00',
        ]);

        SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $saleA->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Void',
            'voided_by' => $this->user->id,
            'voided_at' => '2026-05-12 12:30:00',
        ]);

        SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id,
            'sale_id' => $saleC->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Void',
            'voided_by' => $this->user->id,
            'voided_at' => '2026-05-12 13:00:00',
        ]);

        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $saleA->id,
            'sale_payment_id' => $paymentA->id,
            'reversal_type' => 'void_reversal',
            'amount' => '40.0000',
            'reason_code' => 'mistake',
            'reason_notes' => 'Void reversal coverage',
            'reversed_by' => $this->user->id,
            'reversed_at' => '2026-05-12 12:35:00',
        ]);

        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $saleB->id,
            'sale_payment_id' => $paymentB->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '25.0000',
            'reason_code' => 'return',
            'reason_notes' => 'Refund reversal coverage',
            'reversed_by' => $this->user->id,
            'reversed_at' => '2026-05-12 12:05:00',
        ]);

        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id,
            'sale_id' => $saleC->id,
            'sale_payment_id' => $paymentC->id,
            'reversal_type' => 'void_reversal',
            'amount' => '25.0000',
            'reason_code' => 'mistake',
            'reason_notes' => 'Branch B void reversal coverage',
            'reversed_by' => $this->user->id,
            'reversed_at' => '2026-05-12 13:05:00',
        ]);

        SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
            'status' => SettlementPeriod::STATUS_IN_REVIEW,
            'opened_by' => $this->user->id,
            'opened_at' => '2026-05-12 08:00:00',
            'submitted_by' => $this->user->id,
            'submitted_at' => '2026-05-12 18:00:00',
        ]);

        SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
            'status' => SettlementPeriod::STATUS_LOCKED,
            'opened_by' => $this->user->id,
            'opened_at' => '2026-05-12 08:00:00',
            'locked_by' => $this->user->id,
            'locked_at' => '2026-05-12 19:00:00',
        ]);

        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($this->otherTenant);
        $otherUser = User::where('tenant_id', $this->otherTenant->id)->firstOrFail();
        $otherCash = PaymentMethod::where('tenant_id', $this->otherTenant->id)->firstOrFail();
        $otherSale = Sale::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->otherTenant->id,
            'branch_id' => $otherTenantBranchId,
            'user_id' => $otherUser->id,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => 'OTHER-SALE',
            'status' => 'paid',
            'subtotal' => '99.0000',
            'tax_total' => '0.0000',
            'discount_total' => '0.0000',
            'total' => '99.0000',
            'gross_sales_amount' => '99.0000',
            'vatable_sales_amount' => '99.0000',
            'vat_exempt_sales_amount' => '0.0000',
            'zero_rated_sales_amount' => '0.0000',
            'non_vat_sales_amount' => '0.0000',
            'vat_amount' => '0.0000',
            'statutory_discount_total' => '0.0000',
            'commercial_discount_total' => '0.0000',
            'other_adjustment_total' => '0.0000',
            'contains_statutory_discount' => false,
            'reporting_basis_at' => '2026-05-12 15:00:00',
            'confirmed_at' => '2026-05-12 15:00:00',
        ]);
        $this->createSaleItem($otherSale->id, $otherTenantBranchId, 'vatable', '99.0000', '99.0000', '0.0000', '0.0000', '0.0000', $this->otherTenant->id);
        $otherPayment = SalePayment::create([
            'tenant_id' => $this->otherTenant->id,
            'branch_id' => $otherTenantBranchId,
            'sale_id' => $otherSale->id,
            'payment_method_id' => $otherCash->id,
            'payment_type' => 'cash',
            'provider' => 'cashier',
            'amount' => '99.0000',
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => '2026-05-12 15:00:00',
            'created_by' => $otherUser->id,
        ]);

        PaymentReversal::create([
            'tenant_id' => $this->otherTenant->id,
            'branch_id' => $otherTenantBranchId,
            'sale_id' => $otherSale->id,
            'sale_payment_id' => $otherPayment->id,
            'reversal_type' => 'void_reversal',
            'amount' => '99.0000',
            'reason_code' => 'mistake',
            'reason_notes' => 'Other tenant reversal',
            'reversed_by' => $otherUser->id,
            'reversed_at' => '2026-05-12 15:05:00',
        ]);

        SettlementPeriod::create([
            'tenant_id' => $this->otherTenant->id,
            'branch_id' => $otherTenantBranchId,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
            'status' => SettlementPeriod::STATUS_LOCKED,
            'opened_by' => $otherUser->id,
            'opened_at' => '2026-05-12 08:00:00',
            'locked_by' => $otherUser->id,
            'locked_at' => '2026-05-12 19:00:00',
        ]);

        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($this->tenant);
    }

    protected function createSale(string $branchId, string $saleNumber, string $gross, string $subtotal, string $vatAmount, string $commercialDiscount, string $reportingBasisAt): Sale
    {
        return Sale::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'user_id' => $this->user->id,
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

    protected function createSaleItem(
        string $saleId,
        string $branchId,
        string $taxBucket,
        string $netAmount,
        string $vatableAmount,
        string $vatExemptAmount,
        string $zeroRatedAmount,
        string $nonVatAmount,
        ?string $tenantId = null
    ): void {
        $resolvedTenantId = $tenantId ?? $this->tenant->id;
        $product = Product::factory()->create([
            'tenant_id' => $resolvedTenantId,
            'selling_price' => $netAmount,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);

        SaleItem::insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $resolvedTenantId,
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

    protected function createDiscount(string $saleId, ?string $saleItemId, string $discountAmount, string $createdAt): void
    {
        SaleStatutoryDiscount::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'discount_type' => SaleStatutoryDiscount::DISCOUNT_TYPE_SENIOR_CITIZEN,
            'discount_code' => 'SC',
            'discount_rate' => '0.2000',
            'discount_basis_amount' => '100.0000',
            'discount_amount' => $discountAmount,
            'vat_adjustment_amount' => null,
            'vat_exempt_amount' => null,
            'beneficiary_reference' => 'BEN-001',
            'beneficiary_hash' => 'hash-001',
            'source' => 'manual',
            'snapshot' => ['discount_amount' => $discountAmount],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function createSalePayment(string $saleId, string $branchId, string $amount, string $paidAt): SalePayment
    {
        return SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'sale_id' => $saleId,
            'payment_method_id' => $this->cash->id,
            'payment_type' => 'cash',
            'provider' => 'cashier',
            'amount' => $amount,
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => $paidAt,
            'created_by' => $this->user->id,
        ]);
    }
}