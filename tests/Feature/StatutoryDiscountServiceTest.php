<?php

use App\Models\DiscountType;
use App\Models\SaleItem;
use App\Services\POS\StatutoryDiscountService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->service = app(StatutoryDiscountService::class);

    // Seed discount types directly (test DB may not have them)
    $types = [
        ['code' => 'SC_STANDARD', 'name' => 'Senior Citizen Standard', 'statutory_category' => 'senior', 'default_rate' => 0.20, 'vat_treatment' => 'exempt', 'requires_identity' => true, 'requires_approval' => false, 'applies_to_fnb' => true, 'applies_to_retail' => true],
        ['code' => 'PWD_STANDARD', 'name' => 'PWD Standard', 'statutory_category' => 'pwd', 'default_rate' => 0.20, 'vat_treatment' => 'exempt', 'requires_identity' => true, 'requires_approval' => false, 'applies_to_fnb' => true, 'applies_to_retail' => true],
        ['code' => 'SOLO_PARENT', 'name' => 'Solo Parent', 'statutory_category' => 'solo_parent', 'default_rate' => 0.10, 'vat_treatment' => 'exempt', 'requires_identity' => true, 'requires_approval' => false, 'applies_to_fnb' => true, 'applies_to_retail' => true],
    ];

    foreach ($types as $type) {
        $existing = DiscountType::where('code', $type['code'])->first();
        if (!$existing) {
            DiscountType::create(array_merge($type, ['is_active' => true]));
        }
    }

    $this->scType = DiscountType::where('code', 'SC_STANDARD')->first();
    $this->pwdType = DiscountType::where('code', 'PWD_STANDARD')->first();
    $this->soloParentType = DiscountType::where('code', 'SOLO_PARENT')->first();
});

function makeCartItems(array $items): Collection
{
    return collect($items);
}

it('calculates standard SC discount with VAT exemption for F&B', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'product_name' => 'Meal A',
            'line_subtotal' => 1000.00, // Gross: ₱1,000 (VAT-inclusive)
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
        [
            'product_id' => 'prod-2',
            'product_name' => 'Meal B',
            'line_subtotal' => 500.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'eligible_person_count' => 1,
        'beneficiaries' => [
            ['beneficiary_name' => 'Juan Dela Cruz', 'id_number' => 'SC-001', 'tin' => '123-456-789'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();
    expect($result['gross_eligible_amount'])->toBe(1500.0);

    // VAT removed: 1500 - (1500 / 1.12) = 160.7143
    $expectedNet = round(1500 / 1.12, 4);
    $expectedVat = round(1500 - $expectedNet, 4);
    expect($result['vat_amount_removed'])->toBe($expectedVat);

    // Discountable base: Net (Gross minus VAT) = 1339.2857
    expect($result['discountable_base'])->toBe($expectedNet);

    // Discount: 20% of discountable base
    $expectedDiscount = round($expectedNet * 0.20, 4);
    expect($result['discount_amount'])->toBe($expectedDiscount);

    // Net payable: discountable base - discount
    expect($result['net_payable'])->toBe(round($expectedNet - $expectedDiscount, 4));

    // VAT exempt amount = VAT removed
    expect($result['vat_exempt_amount'])->toBe($result['vat_amount_removed']);

    // Snapshot should be present
    expect($result['calculation_snapshot'])->not->toBeNull();
    expect($result['calculation_snapshot']['engine_version'])->toBe('EPIC42_V1');
    expect($result['calculation_snapshot']['discount_type_code'])->toBe('SC_STANDARD');
});

it('rejects SC discount without beneficiary identity', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 500.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [],
    ]);

    expect($result['is_valid'])->toBeFalse();
    expect($result['errors'])->toContain('Beneficiary identity is required for this discount type.');
});

it('rejects discount when eligible persons exceed total pax', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 1000.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'portion',
        'eligible_person_count' => 3,
        'total_pax_count' => 2,
        'beneficiaries' => [
            ['beneficiary_name' => 'A', 'id_number' => 'SC-1', 'tin' => '1'],
            ['beneficiary_name' => 'B', 'id_number' => 'SC-2', 'tin' => '2'],
            ['beneficiary_name' => 'C', 'id_number' => 'SC-3', 'tin' => '3'],
        ],
    ]);

    expect($result['is_valid'])->toBeFalse();
    expect($result['errors'][0])->toContain('cannot exceed total pax count');
});

it('calculates Solo Parent discount at 10% with VAT exemption', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 1120.00, // VAT-inclusive
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->soloParentType, [
        'application_mode' => 'standard',
        'beneficiaries' => [
            ['beneficiary_name' => 'Maria Santos', 'spic_number' => 'SPIC-001'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();

    // ₱1120 VAT-inclusive → Net = 1120/1.12 = 1000
    $expectedBase = round(1120 / 1.12, 4);
    $expectedDiscount = round($expectedBase * 0.10, 4);

    expect($result['discountable_base'])->toBe($expectedBase);
    expect($result['discount_amount'])->toBe($expectedDiscount);
});

it('scales discount by portion ratio when eligible < total pax', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 1120.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'portion',
        'eligible_person_count' => 1,
        'total_pax_count' => 4,
        'beneficiaries' => [
            ['beneficiary_name' => 'Senior A', 'id_number' => 'SC-1', 'tin' => '1'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();

    // ₱1120 VAT-inclusive → Net = 1000, full 20% discount = 200
    // Portioned at 1/4 = 50
    $fullBase = round(1120 / 1.12, 4);
    $fullDiscount = round($fullBase * 0.20, 4);
    $portionedDiscount = round($fullDiscount * 0.25, 4); // 1/4

    expect($result['discount_amount'])->toBe($portionedDiscount);
});

it('caps MEMC discount at base value times eligible count', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 5000.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'memc',
        'eligible_person_count' => 2,
        'memc_base_value' => 100.00, // Cap: 100 * 2 = 200
        'beneficiaries' => [
            ['beneficiary_name' => 'A', 'id_number' => 'SC-1', 'tin' => '1'],
            ['beneficiary_name' => 'B', 'id_number' => 'SC-2', 'tin' => '2'],
        ],
    ]);

    expect($result['is_valid'])->toBeTrue();
    // ₱5000 VAT-inclusive → Net = 4464.29, 20% = 892.86
    // But MEMC cap = 100 * 2 = 200, so discount should be capped at 200
    expect($result['discount_amount'])->toBe(200.0); // Capped at 200
});

it('prevents combining statutory discounts with promos by default', function () {
    $canCombine = $this->service->canCombineWithPromo($this->scType);
    expect($canCombine)->toBeFalse();
});

it('validates beneficiary metadata for SC/PWD requiring ID and TIN', function () {
    $validation = $this->service->validateBeneficiaryMetadata($this->scType, [
        ['beneficiary_name' => 'Juan', 'id_number' => 'SC-1', 'tin' => '123'],
    ]);
    expect($validation['is_valid'])->toBeTrue();

    $validation = $this->service->validateBeneficiaryMetadata($this->scType, [
        ['beneficiary_name' => 'Juan', 'id_number' => '', 'tin' => ''],
    ]);
    expect($validation['is_valid'])->toBeFalse();
    expect($validation['errors'])->toContain('Beneficiary 1: ID number is required for senior.');
    expect($validation['errors'])->toContain('Beneficiary 1: TIN is required for senior.');
});

it('validates beneficiary metadata for Solo Parent requiring SPIC', function () {
    $validation = $this->service->validateBeneficiaryMetadata($this->soloParentType, [
        ['beneficiary_name' => 'Maria', 'spic_number' => 'SPIC-001'],
    ]);
    expect($validation['is_valid'])->toBeTrue();

    $validation = $this->service->validateBeneficiaryMetadata($this->soloParentType, [
        ['beneficiary_name' => 'Maria', 'spic_number' => ''],
    ]);
    expect($validation['is_valid'])->toBeFalse();
    expect($validation['errors'])->toContain('Beneficiary 1: SPIC number is required for Solo Parent.');
});

it('stores calculation snapshot with all pipeline steps', function () {
    $cartItems = makeCartItems([
        [
            'product_id' => 'prod-1',
            'line_subtotal' => 1120.00,
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
        ],
    ]);

    $result = $this->service->calculate($cartItems, $this->scType, [
        'application_mode' => 'standard',
        'beneficiaries' => [
            ['beneficiary_name' => 'Juan', 'id_number' => 'SC-1', 'tin' => '123'],
        ],
    ]);

    $snapshot = $result['calculation_snapshot'];

    expect($snapshot)->toHaveKey('pipeline');
    expect($snapshot['pipeline'])->toHaveKey('gross_eligible_amount');
    expect($snapshot['pipeline'])->toHaveKey('vat_amount_removed');
    expect($snapshot['pipeline'])->toHaveKey('discountable_base');
    expect($snapshot['pipeline'])->toHaveKey('discount_amount');
    expect($snapshot['pipeline'])->toHaveKey('vat_exempt_amount');
    expect($snapshot['pipeline'])->toHaveKey('net_payable');
    expect($snapshot)->toHaveKey('line_items');
    expect($snapshot)->toHaveKey('beneficiary_count');
});
