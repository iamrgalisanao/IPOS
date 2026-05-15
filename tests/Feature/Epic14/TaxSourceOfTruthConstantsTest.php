<?php

namespace Tests\Feature\Epic14;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatutoryDiscount;
use Tests\TestCase;

class TaxSourceOfTruthConstantsTest extends TestCase
{
    public function test_sale_item_tax_bucket_constants_and_helper_are_available(): void
    {
        $buckets = SaleItem::taxBuckets();

        $this->assertSame([
            SaleItem::TAX_BUCKET_VATABLE,
            SaleItem::TAX_BUCKET_VAT_EXEMPT,
            SaleItem::TAX_BUCKET_ZERO_RATED,
            SaleItem::TAX_BUCKET_NON_VAT,
            SaleItem::TAX_BUCKET_MIXED,
            SaleItem::TAX_BUCKET_UNKNOWN,
        ], $buckets);
    }

    public function test_sale_and_sale_item_tax_source_helpers_share_the_same_allowed_values(): void
    {
        $expected = [
            Sale::TAX_SOURCE_SYSTEM,
            Sale::TAX_SOURCE_POS,
            Sale::TAX_SOURCE_MANUAL,
            Sale::TAX_SOURCE_MIGRATION,
            Sale::TAX_SOURCE_UNKNOWN,
        ];

        $this->assertSame($expected, Sale::taxSources());
        $this->assertSame($expected, Sale::taxComputationSources());
        $this->assertSame($expected, SaleItem::taxSources());
    }

    public function test_statutory_discount_type_constants_and_helper_are_available(): void
    {
        $this->assertSame([
            SaleStatutoryDiscount::DISCOUNT_TYPE_SENIOR_CITIZEN,
            SaleStatutoryDiscount::DISCOUNT_TYPE_PWD,
            SaleStatutoryDiscount::DISCOUNT_TYPE_OTHER,
            SaleStatutoryDiscount::DISCOUNT_TYPE_UNKNOWN,
        ], SaleStatutoryDiscount::discountTypes());
    }

    public function test_sale_reversal_reason_constants_and_helper_are_available(): void
    {
        $this->assertSame([
            Sale::REVERSAL_REASON_VOID,
            Sale::REVERSAL_REASON_REFUND,
            Sale::REVERSAL_REASON_CORRECTION,
            Sale::REVERSAL_REASON_MANUAL_ADJUSTMENT,
            Sale::REVERSAL_REASON_UNKNOWN,
        ], Sale::reversalReasons());
    }
}