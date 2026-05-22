<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesMachineProfile;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutTaxHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private ProductCategory $category;

    private TaxCategory $vatableCategory;
    private TaxCategory $exemptCategory;
    private TaxCategory $zeroRatedCategory;

    private Product $vatableProduct;
    private Product $exemptProduct;
    private Product $zeroRatedProduct;

    private SalesMachineProfile $machineProfile;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch);

        // Seed Sales Machine Profile
        $this->machineProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'MAIN-POS',
            'machine_identification_number' => 'MIN-001',
            'machine_serial_number' => 'SER-001',
            'software_license_number' => 'LIC-001',
            'permit_to_use_number' => 'PTU-001',
            'authority_to_generate_control_number' => 'ATG-001',
            'supplier_name' => 'Supplier',
            'supplier_tin' => '123-456-789-000',
            'supplier_branch_code' => '00001',
            'supplier_accreditation_number' => 'ACC-001',
            'status' => 'active',
        ]);

        // Seed Tax Categories
        $this->vatableCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'VAT12',
            'name'      => 'VAT 12%',
            'tax_type'  => 'VATable',
            'rate'      => 12.00,
        ]);

        $this->exemptCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'VATEX',
            'name'      => 'VAT Exempt',
            'tax_type'  => 'Exempt',
            'rate'      => 0.00,
        ]);

        $this->zeroRatedCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'VATZR',
            'name'      => 'VAT Zero-Rated',
            'tax_type'  => 'Zero-Rated',
            'rate'      => 0.00,
        ]);

        $this->category = ProductCategory::create(['name' => 'Coffee', 'code' => 'COF']);

        $this->vatableProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $this->vatableCategory->id,
            'name'                 => 'Americano (VAT)',
            'sku'                  => 'AMR-VAT',
            'barcode'              => '100001',
            'unit_of_measure'      => 'cup',
            'selling_price'        => 100.00,
            'cost_price'           => 30.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        $this->exemptProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $this->exemptCategory->id,
            'name'                 => 'Croissant (Exempt)',
            'sku'                  => 'CRO-EXM',
            'barcode'              => '100002',
            'unit_of_measure'      => 'piece',
            'selling_price'        => 150.00,
            'cost_price'           => 40.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        $this->zeroRatedProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $this->zeroRatedCategory->id,
            'name'                 => 'Water (Zero-Rated)',
            'sku'                  => 'WAT-ZR',
            'barcode'              => '100003',
            'unit_of_measure'      => 'bottle',
            'selling_price'        => 80.00,
            'cost_price'           => 20.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    public function test_checkout_draft_validation_calculates_vat_inclusive_totals(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 2],    // 200.00 gross (inclusive VAT is 200 - 200/1.12 = 21.4286, net is 178.5714)
                ['product_id' => $this->exemptProduct->id, 'quantity' => 1],     // 150.00 gross
                ['product_id' => $this->zeroRatedProduct->id, 'quantity' => 1],  // 80.00 gross
            ],
        ];

        // Total gross is 200 + 150 + 80 = 430.00
        // Expected tax is 21.4286

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals('430.0000', $data['server_totals']['subtotal']);
        $this->assertEquals('21.4286', $data['server_totals']['tax_total']);
        $this->assertEquals('430.0000', $data['server_totals']['total']);
    }

    public function test_create_sale_calculates_inclusive_tax_and_populates_epic14_columns(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 2],    // 200.00 gross
                ['product_id' => $this->exemptProduct->id, 'quantity' => 1],     // 150.00 gross
                ['product_id' => $this->zeroRatedProduct->id, 'quantity' => 1],  // 80.00 gross
            ],
        ];

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $response->assertStatus(200);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 3);

        $sale = Sale::first();

        // 1. Core aggregates
        $this->assertEquals('430.0000', $sale->subtotal);
        $this->assertEquals('21.4286', $sale->tax_total);
        $this->assertEquals('430.0000', $sale->total);

        // 2. Epic 14 tax breakdown columns
        $this->assertEquals('430.0000', $sale->gross_sales_amount);
        $this->assertEquals('178.5714', $sale->vatable_sales_amount);
        $this->assertEquals('150.0000', $sale->vat_exempt_sales_amount);
        $this->assertEquals('80.0000', $sale->zero_rated_sales_amount);
        $this->assertEquals('0.0000', $sale->non_vat_sales_amount);
        $this->assertEquals('21.4286', $sale->vat_amount);

        // 3. Compliance identifiers
        $this->assertEquals('EPIC14_V1', $sale->compliance_version);
        $this->assertEquals('BIR_VAT_2026_BASELINE', $sale->tax_source_version);
        $this->assertEquals('system', $sale->tax_computation_source);

        // 4. Snapshots & dates
        $this->assertNotNull($sale->tax_profile_snapshot);
        $this->assertEquals('MAIN-POS', $sale->tax_profile_snapshot['profile_code']);
        $this->assertNotNull($sale->invoice_issued_at);
        $this->assertNotNull($sale->reporting_basis_at);
        $this->assertNotNull($sale->confirmed_at);

        // 5. Line items verification
        $vatItem = SaleItem::where('product_id', $this->vatableProduct->id)->first();
        $this->assertEquals('vatable', $vatItem->tax_bucket);
        $this->assertEquals('178.5714', $vatItem->net_amount);
        $this->assertEquals('178.5714', $vatItem->vatable_amount);
        $this->assertEquals('0.0000', $vatItem->vat_exempt_amount);
        $this->assertEquals('21.4286', $vatItem->tax_amount);
        $this->assertEquals('200.0000', $vatItem->line_total);
        $this->assertNotNull($vatItem->tax_snapshot);
        $this->assertEquals('vatable', $vatItem->tax_snapshot['tax_bucket']);

        $exmItem = SaleItem::where('product_id', $this->exemptProduct->id)->first();
        $this->assertEquals('vat_exempt', $exmItem->tax_bucket);
        $this->assertEquals('150.0000', $exmItem->net_amount);
        $this->assertEquals('150.0000', $exmItem->vat_exempt_amount);
        $this->assertEquals('0.0000', $exmItem->vatable_amount);
        $this->assertEquals('0.0000', $exmItem->tax_amount);
        $this->assertEquals('150.0000', $exmItem->line_total);

        $zrItem = SaleItem::where('product_id', $this->zeroRatedProduct->id)->first();
        $this->assertEquals('zero_rated', $zrItem->tax_bucket);
        $this->assertEquals('80.0000', $zrItem->net_amount);
        $this->assertEquals('80.0000', $zrItem->zero_rated_amount);
        $this->assertEquals('0.0000', $zrItem->tax_amount);
        $this->assertEquals('80.0000', $zrItem->line_total);
    }

    public function test_vat_inclusive_exact_100_pesos_extraction(): void
    {
        // VATable item priced at 100 x 1:
        // gross total = 100.00
        // net of VAT = 89.2857
        // VAT amount = 10.7143
        // grand total = 100.00
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 1],
            ],
        ];

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('100.0000', $data['server_totals']['subtotal']);
        $this->assertEquals('10.7143', $data['server_totals']['tax_total']);
        $this->assertEquals('100.0000', $data['server_totals']['total']);

        // Create Sale
        $saleResponse = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $saleResponse->assertStatus(200);
        $sale = Sale::where('client_request_uuid', $payload['client_request_uuid'])->firstOrFail();

        $this->assertEquals('100.0000', $sale->subtotal);
        $this->assertEquals('10.7143', $sale->tax_total);
        $this->assertEquals('100.0000', $sale->total);

        // Line item check
        $item = $sale->items->first();
        $this->assertEquals('100.0000', $item->subtotal);
        $this->assertEquals('10.7143', $item->tax_amount);
        $this->assertEquals('89.2857', $item->net_amount);
        $this->assertEquals('100.0000', $item->line_total);

        // Quantity multiplication: 100 x 3
        $payload3 = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 3],
            ],
        ];

        $response3 = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload3);

        $response3->assertStatus(200);
        $data3 = $response3->json();
        $this->assertEquals('300.0000', $data3['server_totals']['subtotal']);
        $this->assertEquals('32.1429', $data3['server_totals']['tax_total']);
        $this->assertEquals('300.0000', $data3['server_totals']['total']);
    }

    public function test_tax_category_separation_and_non_collapse(): void
    {
        // We will seed a Non-VAT tax category and standard non-vat product
        $nonVatCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'VATNV',
            'name'      => 'Non-VAT Category',
            'tax_type'  => 'non-vat',
            'rate'      => 0.00,
        ]);

        $nonVatProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $nonVatCategory->id,
            'name'                 => 'Juice (Non-VAT)',
            'sku'                  => 'JUC-NVT',
            'barcode'              => '100004',
            'unit_of_measure'      => 'bottle',
            'selling_price'        => 120.00,
            'cost_price'           => 30.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        // mixed cart payload
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 2],    // 200.00 gross (VAT = 21.4286, net = 178.5714)
                ['product_id' => $this->exemptProduct->id, 'quantity' => 1],     // 150.00 gross (Exempt = 150.00)
                ['product_id' => $this->zeroRatedProduct->id, 'quantity' => 1],  // 80.00 gross  (Zero-Rated = 80.00)
                ['product_id' => $nonVatProduct->id, 'quantity' => 1],           // 120.00 gross (Non-VAT = 120.00)
            ],
        ];

        // Total gross = 200 + 150 + 80 + 120 = 550.00
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $response->assertStatus(200);

        $sale = Sale::where('client_request_uuid', $payload['client_request_uuid'])->firstOrFail();

        // Validate distinct separation
        $this->assertEquals('550.0000', $sale->gross_sales_amount);
        $this->assertEquals('178.5714', $sale->vatable_sales_amount);
        $this->assertEquals('150.0000', $sale->vat_exempt_sales_amount);
        $this->assertEquals('80.0000', $sale->zero_rated_sales_amount);
        $this->assertEquals('120.0000', $sale->non_vat_sales_amount);
        $this->assertEquals('21.4286', $sale->vat_amount);
        $this->assertEquals('550.0000', $sale->total);

        // Validate line item buckets are separate and distinct
        $itemVat = $sale->items->where('product_id', $this->vatableProduct->id)->first();
        $this->assertEquals('vatable', $itemVat->tax_bucket);
        $this->assertEquals('178.5714', $itemVat->net_amount);
        $this->assertEquals('178.5714', $itemVat->vatable_amount);
        $this->assertEquals('0.0000', $itemVat->vat_exempt_amount);
        $this->assertEquals('0.0000', $itemVat->zero_rated_amount);
        $this->assertEquals('0.0000', $itemVat->non_vat_amount);

        $itemExempt = $sale->items->where('product_id', $this->exemptProduct->id)->first();
        $this->assertEquals('vat_exempt', $itemExempt->tax_bucket);
        $this->assertEquals('150.0000', $itemExempt->net_amount);
        $this->assertEquals('0.0000', $itemExempt->vatable_amount);
        $this->assertEquals('150.0000', $itemExempt->vat_exempt_amount);
        $this->assertEquals('0.0000', $itemExempt->zero_rated_amount);
        $this->assertEquals('0.0000', $itemExempt->non_vat_amount);

        $itemZero = $sale->items->where('product_id', $this->zeroRatedProduct->id)->first();
        $this->assertEquals('zero_rated', $itemZero->tax_bucket);
        $this->assertEquals('80.0000', $itemZero->net_amount);
        $this->assertEquals('0.0000', $itemZero->vatable_amount);
        $this->assertEquals('0.0000', $itemZero->vat_exempt_amount);
        $this->assertEquals('80.0000', $itemZero->zero_rated_amount);
        $this->assertEquals('0.0000', $itemZero->non_vat_amount);

        $itemNon = $sale->items->where('product_id', $nonVatProduct->id)->first();
        $this->assertEquals('non_vat', $itemNon->tax_bucket);
        $this->assertEquals('120.0000', $itemNon->net_amount);
        $this->assertEquals('0.0000', $itemNon->vatable_amount);
        $this->assertEquals('0.0000', $itemNon->vat_exempt_amount);
        $this->assertEquals('0.0000', $itemNon->zero_rated_amount);
        $this->assertEquals('120.0000', $itemNon->non_vat_amount);
    }

    public function test_sc_pwd_discount_boundary_preserves_is_discountable_flag(): void
    {
        // Product A: is_discountable = true
        $discountableProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $this->vatableCategory->id,
            'name'                 => 'Discountable Item',
            'sku'                  => 'DSC-YES',
            'selling_price'        => 100.00,
            'cost_price'           => 30.00,
            'is_discountable'      => true,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        // Product B: is_discountable = false
        $nonDiscountableProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $this->vatableCategory->id,
            'name'                 => 'Non-Discountable Item',
            'sku'                  => 'DSC-NO',
            'selling_price'        => 200.00,
            'cost_price'           => 60.00,
            'is_discountable'      => false,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $discountableProduct->id, 'quantity' => 1],
                ['product_id' => $nonDiscountableProduct->id, 'quantity' => 1],
            ],
        ];

        // 1. Checkout validation preserves flags
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload);

        $response->assertStatus(200);

        // 2. Create sale preserves eligibility flag and no new SC/PWD discount computation is performed yet
        // (SC/PWD transaction computation deferred; only product eligibility flag and tax bucket safety are currently testable)
        $saleResponse = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $saleResponse->assertStatus(200);
        $sale = Sale::where('client_request_uuid', $payload['client_request_uuid'])->firstOrFail();

        $this->assertEquals('0.0000', $sale->statutory_discount_total);
        $this->assertFalse($sale->contains_statutory_discount);

        $itemA = $sale->items->where('product_id', $discountableProduct->id)->first();
        $this->assertTrue($itemA->tax_snapshot['is_discountable']);

        $itemB = $sale->items->where('product_id', $nonDiscountableProduct->id)->first();
        $this->assertFalse($itemB->tax_snapshot['is_discountable']);
    }

    public function test_sale_header_and_item_compliance_columns_are_fully_populated(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 1],
            ],
        ];

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $response->assertStatus(200);
        $sale = Sale::where('client_request_uuid', $payload['client_request_uuid'])->firstOrFail();

        // 1. Header compliance columns
        $this->assertEquals('100.0000', $sale->gross_sales_amount);
        $this->assertEquals('89.2857', $sale->vatable_sales_amount);
        $this->assertEquals('0.0000', $sale->vat_exempt_sales_amount);
        $this->assertEquals('0.0000', $sale->zero_rated_sales_amount);
        $this->assertEquals('0.0000', $sale->non_vat_sales_amount);
        $this->assertEquals('10.7143', $sale->vat_amount);
        $this->assertEquals('EPIC14_V1', $sale->compliance_version);
        $this->assertEquals('BIR_VAT_2026_BASELINE', $sale->tax_source_version);
        $this->assertEquals('system', $sale->tax_computation_source);
        $this->assertNotNull($sale->tax_profile_snapshot);
        $this->assertNotNull($sale->invoice_issued_at);
        $this->assertNotNull($sale->reporting_basis_at);
        $this->assertNotNull($sale->confirmed_at);

        // 2. Line item compliance columns
        $item = $sale->items->first();
        $this->assertEquals('vatable', $item->tax_bucket);
        $this->assertEquals('89.2857', $item->net_amount);
        $this->assertEquals('89.2857', $item->vatable_amount);
        $this->assertEquals('0.0000', $item->vat_exempt_amount);
        $this->assertEquals('0.0000', $item->zero_rated_amount);
        $this->assertEquals('0.0000', $item->non_vat_amount);
        $this->assertEquals('system', $item->tax_source);
        $this->assertNotNull($item->tax_snapshot);
        $this->assertEquals('vatable', $item->tax_snapshot['tax_bucket']);
    }

    public function test_zero_mutation_guarantee_no_inventory_or_reporting_export_side_effects(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->vatableProduct->id, 'quantity' => 1],
            ],
        ];

        // Capture starting state of unrelated tables
        $paymentsCount = \Illuminate\Support\Facades\Schema::hasTable('payments') ? \DB::table('payments')->count() : 0;
        $movementsCount = \Illuminate\Support\Facades\Schema::hasTable('inventory_movements') ? \DB::table('inventory_movements')->count() : 0;
        $outboxCount = \Illuminate\Support\Facades\Schema::hasTable('accounting_outbox') ? \DB::table('accounting_outbox')->count() : 0;

        // Perform checkout flow validation and creation
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload)
            ->assertStatus(200);

        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload)
            ->assertStatus(200);

        // Verify state of unrelated tables remains absolutely unchanged (zero mutation)
        $this->assertEquals($paymentsCount, \Illuminate\Support\Facades\Schema::hasTable('payments') ? \DB::table('payments')->count() : 0);
        $this->assertEquals($movementsCount, \Illuminate\Support\Facades\Schema::hasTable('inventory_movements') ? \DB::table('inventory_movements')->count() : 0);
        $this->assertEquals($outboxCount, \Illuminate\Support\Facades\Schema::hasTable('accounting_outbox') ? \DB::table('accounting_outbox')->count() : 0);
    }
}
