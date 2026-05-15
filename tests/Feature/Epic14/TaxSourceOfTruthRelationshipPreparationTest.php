<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatutoryDiscount;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class TaxSourceOfTruthRelationshipPreparationTest extends TestCase
{
    public function test_sale_exposes_epic14_relationships(): void
    {
        $sale = new Sale();

        $machineProfileRelation = $sale->salesMachineProfile();
        $discountsRelation = $sale->statutoryDiscounts();
        $reversalParentRelation = $sale->reversalOfSale();
        $reversalChildrenRelation = $sale->reversals();

        $this->assertInstanceOf(BelongsTo::class, $machineProfileRelation);
        $this->assertSame('sales_machine_profile_id', $machineProfileRelation->getForeignKeyName());
        $this->assertInstanceOf(SalesMachineProfile::class, $machineProfileRelation->getRelated());

        $this->assertInstanceOf(HasMany::class, $discountsRelation);
        $this->assertSame('sale_id', $discountsRelation->getForeignKeyName());
        $this->assertInstanceOf(SaleStatutoryDiscount::class, $discountsRelation->getRelated());

        $this->assertInstanceOf(BelongsTo::class, $reversalParentRelation);
        $this->assertSame('reversal_of_sale_id', $reversalParentRelation->getForeignKeyName());
        $this->assertInstanceOf(Sale::class, $reversalParentRelation->getRelated());

        $this->assertInstanceOf(HasMany::class, $reversalChildrenRelation);
        $this->assertSame('reversal_of_sale_id', $reversalChildrenRelation->getForeignKeyName());
        $this->assertInstanceOf(Sale::class, $reversalChildrenRelation->getRelated());
    }

    public function test_sale_item_existing_and_epic14_relationships_are_intact(): void
    {
        $saleItem = new SaleItem();

        $saleRelation = $saleItem->sale();
        $discountsRelation = $saleItem->statutoryDiscounts();

        $this->assertInstanceOf(BelongsTo::class, $saleRelation);
        $this->assertSame('sale_id', $saleRelation->getForeignKeyName());
        $this->assertInstanceOf(Sale::class, $saleRelation->getRelated());

        $this->assertInstanceOf(HasMany::class, $discountsRelation);
        $this->assertSame('sale_item_id', $discountsRelation->getForeignKeyName());
        $this->assertInstanceOf(SaleStatutoryDiscount::class, $discountsRelation->getRelated());
    }

    public function test_sales_machine_profile_relationships_are_available(): void
    {
        $profile = new SalesMachineProfile();

        $salesRelation = $profile->sales();
        $tenantRelation = $profile->tenant();
        $branchRelation = $profile->branch();

        $this->assertInstanceOf(HasMany::class, $salesRelation);
        $this->assertSame('sales_machine_profile_id', $salesRelation->getForeignKeyName());
        $this->assertInstanceOf(Sale::class, $salesRelation->getRelated());

        $this->assertInstanceOf(BelongsTo::class, $tenantRelation);
        $this->assertSame('tenant_id', $tenantRelation->getForeignKeyName());
        $this->assertInstanceOf(Tenant::class, $tenantRelation->getRelated());

        $this->assertInstanceOf(BelongsTo::class, $branchRelation);
        $this->assertSame('branch_id', $branchRelation->getForeignKeyName());
        $this->assertInstanceOf(Branch::class, $branchRelation->getRelated());
    }

    public function test_sale_statutory_discount_relationships_are_available(): void
    {
        $discount = new SaleStatutoryDiscount();

        $saleRelation = $discount->sale();
        $saleItemRelation = $discount->saleItem();

        $this->assertInstanceOf(BelongsTo::class, $saleRelation);
        $this->assertSame('sale_id', $saleRelation->getForeignKeyName());
        $this->assertInstanceOf(Sale::class, $saleRelation->getRelated());

        $this->assertInstanceOf(BelongsTo::class, $saleItemRelation);
        $this->assertSame('sale_item_id', $saleItemRelation->getForeignKeyName());
        $this->assertInstanceOf(SaleItem::class, $saleItemRelation->getRelated());
    }
}