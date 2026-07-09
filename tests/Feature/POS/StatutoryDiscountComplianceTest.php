<?php

use App\Models\Branch;
use App\Models\DiscountType;
use App\Models\ManagerApproval;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleStatutoryDiscount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Services\POS\StatutoryDiscountService;
use App\Services\POS\VoidService;
use App\Services\POS\RefundService;
use App\Services\Shift\ShiftReportService;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(TenantContext::class)->clear();
    app(BranchContext::class)->clear();

    $this->tenant = Tenant::factory()->create(['status' => 'active']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'active',
    ]);
    app(BranchContext::class)->setBranch($this->branch);

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'active',
    ]);
    $this->actingAs($this->user);

    // Seed discount types
    $this->scType = DiscountType::create([
        'code' => 'SC_STANDARD',
        'name' => 'Senior Citizen Standard',
        'statutory_category' => 'senior',
        'default_rate' => 0.20,
        'vat_treatment' => 'exempt',
        'requires_identity' => true,
        'requires_approval' => false,
        'applies_to_fnb' => true,
        'applies_to_retail' => true,
        'is_active' => true,
    ]);

    $this->pwdType = DiscountType::create([
        'code' => 'PWD_STANDARD',
        'name' => 'PWD Standard',
        'statutory_category' => 'pwd',
        'default_rate' => 0.20,
        'vat_treatment' => 'exempt',
        'requires_identity' => true,
        'requires_approval' => false,
        'applies_to_fnb' => true,
        'applies_to_retail' => true,
        'is_active' => true,
    ]);

    $this->approvalRequiredType = DiscountType::create([
        'code' => 'SC_SPECIAL',
        'name' => 'Senior Citizen Special',
        'statutory_category' => 'senior',
        'default_rate' => 0.20,
        'vat_treatment' => 'exempt',
        'requires_identity' => true,
        'requires_approval' => true,
        'applies_to_fnb' => true,
        'applies_to_retail' => true,
        'is_active' => true,
    ]);

    $this->service = app(StatutoryDiscountService::class);
});

// ============================================================================
// 1. Calculation Accuracy Tests
// ============================================================================

it('calculates SC 20% discount with VAT exemption correctly', function () {
    $cartItems = collect([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 1120.00, // VAT-inclusive
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [
            ['beneficiary_name' => 'Juan Dela Cruz', 'id_number' => 'SC-001'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();
    // ₱1120 VAT-inclusive → Net = 1000, 20% discount = 200
    expect($result['discountable_base'])->toBe(round(1120 / 1.12, 4));
    expect($result['discount_amount'])->toBe(round(round(1120 / 1.12, 4) * 0.20, 4));
    expect($result['vat_exempt_amount'])->toBe(round(1120 - round(1120 / 1.12, 4), 4));
});

it('calculates PWD 20% discount with VAT exemption correctly', function () {
    $cartItems = collect([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 560.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->pwdType, [
        'application_mode' => 'standard',
        'beneficiaries' => [
            ['beneficiary_name' => 'Maria Santos', 'id_number' => 'PWD-001'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();
    // ₱560 VAT-inclusive → Net = 500, 20% discount = 100
    expect($result['discountable_base'])->toBe(round(560 / 1.12, 4));
    expect($result['discount_amount'])->toBe(round(round(560 / 1.12, 4) * 0.20, 4));
});

it('rejects discount without beneficiary identity when required', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 500.00, 'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [],
    ]);

    expect($result['is_valid'])->toBeFalse();
    expect($result['errors'])->toContain('Beneficiary identity is required for this discount type.');
});

it('rejects discount when eligible persons exceed total pax', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 1000.00, 'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'portion',
        'eligible_person_count' => 3,
        'total_pax_count' => 2,
        'beneficiaries' => [
            ['beneficiary_name' => 'A', 'id_number' => 'SC-1'],
            ['beneficiary_name' => 'B', 'id_number' => 'SC-2'],
            ['beneficiary_name' => 'C', 'id_number' => 'SC-3'],
        ],
    ]);

    expect($result['is_valid'])->toBeFalse();
    expect($result['errors'][0])->toContain('cannot exceed total pax count');
});

it('scales discount by portion ratio when eligible < total pax', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 1120.00, 'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'portion',
        'eligible_person_count' => 1,
        'total_pax_count' => 4,
        'beneficiaries' => [
            ['beneficiary_name' => 'Senior A', 'id_number' => 'SC-1'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();
    $fullDiscount = round(round(1120 / 1.12, 4) * 0.20, 4);
    $portionedDiscount = round($fullDiscount * 0.25, 4); // 1/4
    expect($result['discount_amount'])->toBe($portionedDiscount);
});

// ============================================================================
// 2. Sale Creation Integration Tests
// ============================================================================

it('persists statutory discount records when sale is created with discount', function () {
    $sale = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
        'contains_statutory_discount' => true,
        'statutory_discount_total' => 200.00,
        'vat_exempt_sales_amount' => 120.00,
        'vatable_sales_amount' => 880.00,
        'vat_amount' => 105.60,
    ]);

    SaleStatutoryDiscount::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale->id,
        'discount_type' => 'senior_citizen',
        'discount_code' => 'SC_STANDARD',
        'discount_rate' => 0.20,
        'discount_basis_amount' => 1000.00,
        'discount_amount' => 200.00,
        'vat_adjustment_amount' => 120.00,
        'vat_exempt_amount' => 120.00,
        'beneficiary_reference' => 'SC-001',
        'source' => 'pos',
        'snapshot' => ['engine_version' => 'EPIC42_V1'],
    ]);

    $persisted = SaleStatutoryDiscount::where('sale_id', $sale->id)->first();
    expect($persisted)->not->toBeNull();
    expect($persisted->discount_amount)->toBe('200.0000');
    expect($persisted->discount_code)->toBe('SC_STANDARD');
    expect($persisted->snapshot['engine_version'])->toBe('EPIC42_V1');

    expect($sale->contains_statutory_discount)->toBeTrue();
    expect($sale->statutory_discount_total)->toBe('200.0000');
});

// ============================================================================
// 3. Z-Reading Aggregation Tests
// ============================================================================

it('aggregates statutory discounts separately from commercial in Z-Report', function () {
    $shift = Shift::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'cashier_id' => $this->user->id,
        'status' => 'closed',
    ]);

    // Sale with statutory discount
    $sale1 = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
        'total' => 800.00,
        'statutory_discount_total' => 200.00,
        'commercial_discount_total' => 0,
        'vat_exempt_sales_amount' => 120.00,
        'vatable_sales_amount' => 680.00,
        'vat_amount' => 81.60,
    ]);

    // Sale with commercial discount only
    $sale2 = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
        'total' => 900.00,
        'statutory_discount_total' => 0,
        'commercial_discount_total' => 100.00,
        'vat_exempt_sales_amount' => 0,
        'vatable_sales_amount' => 900.00,
        'vat_amount' => 108.00,
    ]);

    // Link payments to shift
    SalePayment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale1->id,
        'shift_id' => $shift->id,
        'amount' => 800.00,
    ]);
    SalePayment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale2->id,
        'shift_id' => $shift->id,
        'amount' => 900.00,
    ]);

    $reportService = app(ShiftReportService::class);
    $report = $reportService->generateSummary($shift, true);

    expect($report['sales']['discount_breakdown']['statutory'])->toBe('200.0000');
    expect($report['sales']['discount_breakdown']['commercial'])->toBe('100.0000');
    expect($report['sales']['discount_breakdown']['total'])->toBe('300.0000');
    expect($report['sales']['tax_breakdown']['exempt'])->toBe('120.0000');
});

// ============================================================================
// 4. Manager Approval Tests
// ============================================================================

it('creates manager approval record with correct metadata', function () {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'active',
    ]);

    $approval = ManagerApproval::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'user_id' => $manager->id,
        'requesting_user_id' => $this->user->id,
        'approvable_type' => 'SaleStatutoryDiscount',
        'approvable_id' => Str::uuid()->toString(),
        'action' => 'approve',
        'reason' => 'Verified senior citizen ID at POS',
        'metadata' => [
            'discount_type_id' => $this->approvalRequiredType->id,
            'amount' => 200.00,
        ],
    ]);

    expect($approval)->not->toBeNull();
    expect($approval->action)->toBe('approve');
    expect($approval->metadata['discount_type_id'])->toBe($this->approvalRequiredType->id);
    expect($approval->manager->id)->toBe($manager->id);
    expect($approval->requester->id)->toBe($this->user->id);
});

it('identifies discount types that require manager approval', function () {
    expect($this->scType->requires_approval)->toBeFalse();
    expect($this->approvalRequiredType->requires_approval)->toBeTrue();
});

// ============================================================================
// 5. Void Reversal Tests
// ============================================================================

it('reverses statutory discount totals when sale is voided', function () {
    $sale = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
        'total' => 800.00,
        'contains_statutory_discount' => true,
        'statutory_discount_total' => 200.00,
        'vat_exempt_sales_amount' => 120.00,
        'vatable_sales_amount' => 680.00,
        'vat_amount' => 81.60,
    ]);

    SaleStatutoryDiscount::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale->id,
        'discount_type' => 'senior_citizen',
        'discount_code' => 'SC_STANDARD',
        'discount_rate' => 0.20,
        'discount_basis_amount' => 1000.00,
        'discount_amount' => 200.00,
        'vat_adjustment_amount' => 120.00,
        'vat_exempt_amount' => 120.00,
        'source' => 'pos',
        'snapshot' => ['engine_version' => 'EPIC42_V1'],
    ]);

    // Create a payment linked to an open shift
    $shift = Shift::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'cashier_id' => $this->user->id,
        'status' => Shift::STATUS_OPEN,
    ]);

    $paymentMethod = \App\Models\PaymentMethod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Cash',
        'code' => 'cash',
    ]);

    SalePayment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale->id,
        'shift_id' => $shift->id,
        'payment_method_id' => $paymentMethod->id,
        'amount' => 800.00,
    ]);

    $voidService = app(VoidService::class);
    $void = $voidService->void($sale, 'CANCELLATION', 'Customer cancelled order');

    $sale->refresh();

    expect($sale->status)->toBe('voided');
    expect($sale->contains_statutory_discount)->toBeFalse();
    expect((float) $sale->statutory_discount_total)->toBe(0.0);
    // VAT-exempt should be reversed back into vatable
    expect((float) $sale->vat_exempt_sales_amount)->toBe(0.0);
    expect((float) $sale->vatable_sales_amount)->toBe(800.00);

    // Original SaleStatutoryDiscount record should still exist for audit trail
    $statutoryRecord = SaleStatutoryDiscount::where('sale_id', $sale->id)->first();
    expect($statutoryRecord)->not->toBeNull();
    expect((float) $statutoryRecord->discount_amount)->toBe(200.00);
});

// ============================================================================
// 6. Refund Reversal Tests
// ============================================================================

it('proportionally reverses statutory discount on partial refund', function () {
    $sale = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
        'total' => 1000.00,
        'subtotal' => 892.86,
        'tax_total' => 107.14,
        'contains_statutory_discount' => true,
        'statutory_discount_total' => 200.00,
        'vat_exempt_sales_amount' => 107.14,
        'vatable_sales_amount' => 785.72,
        'vat_amount' => 94.29,
    ]);

    SaleStatutoryDiscount::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale->id,
        'discount_type' => 'senior_citizen',
        'discount_code' => 'SC_STANDARD',
        'discount_rate' => 0.20,
        'discount_basis_amount' => 1000.00,
        'discount_amount' => 200.00,
        'vat_adjustment_amount' => 107.14,
        'vat_exempt_amount' => 107.14,
        'source' => 'pos',
        'snapshot' => ['engine_version' => 'EPIC42_V1'],
    ]);

    // Create sale items
    $product = Product::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_inventory_tracked' => false,
    ]);

    $saleItem = SaleItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 500.00,
        'line_total' => 1000.00,
    ]);

    // Create payment
    $shift = Shift::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'cashier_id' => $this->user->id,
        'status' => Shift::STATUS_OPEN,
    ]);

    $paymentMethod = \App\Models\PaymentMethod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Cash',
        'code' => 'cash',
    ]);

    SalePayment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'sale_id' => $sale->id,
        'shift_id' => $shift->id,
        'payment_method_id' => $paymentMethod->id,
        'amount' => 1000.00,
    ]);

    // Refund 1 of 2 items (50% refund)
    $refundService = app(RefundService::class);
    $refund = $refundService->refund(
        $sale,
        [['sale_item_id' => $saleItem->id, 'quantity' => 1, 'restock_action' => 'pending_inspection']],
        'RETURN',
        'Customer returned item',
        $shift->id
    );

    $sale->refresh();

    expect($sale->status)->toBe('partially_refunded');
    // 50% of statutory discount should be reversed
    expect((float) $sale->statutory_discount_total)->toBe(100.0);
    expect($sale->contains_statutory_discount)->toBeTrue();
    // VAT-exempt should be halved
    expect((float) $sale->vat_exempt_sales_amount)->toBe(round(107.14 * 0.5, 4));
});

// ============================================================================
// 7. Calculation Snapshot Integrity Tests
// ============================================================================

it('produces immutable calculation snapshot with engine version', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 1120.00, 'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [['beneficiary_name' => 'Juan', 'id_number' => 'SC-001']],
    ]);

    expect($result['calculation_snapshot'])->not->toBeNull();
    expect($result['calculation_snapshot']['engine_version'])->toBe('EPIC42_V1');
    expect($result['calculation_snapshot']['discount_type_code'])->toBe('SC_STANDARD');
    expect($result['calculation_snapshot']['vat_rate'])->toBe(12.0);
});

it('handles zero-rated items correctly (no VAT to remove)', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 500.00, 'tax_bucket' => SaleItem::TAX_BUCKET_ZERO_RATED],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [['beneficiary_name' => 'Juan', 'id_number' => 'SC-001']],
    ]);

    expect($result['is_valid'])->toBeTrue();
    // Zero-rated: no VAT to remove, discountable base = gross
    expect($result['vat_amount_removed'])->toBe(0.0);
    expect($result['discountable_base'])->toBe(500.0);
    expect($result['discount_amount'])->toBe(100.0); // 20% of 500
});

it('handles VAT-exempt items correctly (no VAT to remove)', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 500.00, 'tax_bucket' => SaleItem::TAX_BUCKET_VAT_EXEMPT],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [['beneficiary_name' => 'Juan', 'id_number' => 'SC-001']],
    ]);

    expect($result['is_valid'])->toBeTrue();
    // Already VAT-exempt: no VAT to remove, discountable base = gross
    expect($result['vat_amount_removed'])->toBe(0.0);
    expect($result['discountable_base'])->toBe(500.0);
    expect($result['discount_amount'])->toBe(100.0); // 20% of 500
});

it('rejects inactive discount types', function () {
    $this->scType->update(['is_active' => false]);

    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 500.00, 'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [['beneficiary_name' => 'Juan', 'id_number' => 'SC-001']],
    ]);

    expect($result['is_valid'])->toBeFalse();
    expect($result['errors'])->toContain('Discount type is not active.');
});

it('rejects discount when no eligible items exist', function () {
    $cartItems = collect([
        ['product_id' => 'prod-1', 'line_subtotal' => 500.00, 'tax_bucket' => SaleItem::TAX_BUCKET_NON_VAT],
    ]);

    // Create a discount type that only applies to F&B, not retail
    $fnbOnlyType = DiscountType::create([
        'code' => 'SC_FNB_ONLY',
        'name' => 'SC F&B Only',
        'statutory_category' => 'senior',
        'default_rate' => 0.20,
        'vat_treatment' => 'exempt',
        'requires_identity' => true,
        'requires_approval' => false,
        'applies_to_fnb' => true,
        'applies_to_retail' => false,
        'is_active' => true,
    ]);

    $result = $this->service->calculate($cartItems, $fnbOnlyType, [
        'application_mode' => 'standard',
        'beneficiaries' => [['beneficiary_name' => 'Juan', 'id_number' => 'SC-001']],
    ]);

    expect($result['is_valid'])->toBeFalse();
    expect($result['errors'])->toContain('No eligible items for this discount type.');
});
