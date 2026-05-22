<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Tax\SalesTaxReportingQueryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxReportingInclusiveAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected PaymentMethod $cash;
    protected SalesTaxReportingQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'name' => 'Compliance Branch',
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cash = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'status' => 'active',
        ]);

        $this->service = app(SalesTaxReportingQueryService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    /**
     * Test 1 & 2 & 3: VAT-inclusive stored values are reported precisely,
     * base gross of 100.00 reports 89.2857 net and 10.7143 VAT, and
     * is NOT calculated as tax-on-top.
     */
    public function test_vatable_gross_100_reports_net_vatable_sales_89_2857_and_vat_10_7143_inclusive(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('100.0000', '89.2857', '0.0000', '0.0000', '0.0000', '10.7143', $saleDate);
        $this->createSaleItemRecord($sale->id, 'vatable', '100.0000', '89.2857', '0.0000', '0.0000', '0.0000');

        $summary = $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        // Verify Gross remains 100.0000 (not tax-on-top 112.0000)
        $this->assertEquals('100.0000', $summary['gross_sales']);
        $this->assertEquals('100.0000', $summary['net_sales']);

        // Verify Net and VAT splits
        $this->assertEquals('89.2857', $summary['vatable_sales']);
        $this->assertEquals('10.7143', $summary['vat_amount']);

        // Other buckets must be zero
        $this->assertEquals('0.0000', $summary['vat_exempt_sales']);
        $this->assertEquals('0.0000', $summary['zero_rated_sales']);
        $this->assertEquals('0.0000', $summary['non_vat_sales']);
    }

    /**
     * Test 4: VAT-Exempt sales report under the exempt bucket and don't leak to standard VATable.
     */
    public function test_vat_exempt_sales_report_under_vat_exempt_bucket_correctly(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('150.0000', '0.0000', '150.0000', '0.0000', '0.0000', '0.0000', $saleDate);
        $this->createSaleItemRecord($sale->id, 'vat_exempt', '150.0000', '0.0000', '150.0000', '0.0000', '0.0000');

        $summary = $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        $this->assertEquals('150.0000', $summary['gross_sales']);
        $this->assertEquals('150.0000', $summary['vat_exempt_sales']);
        $this->assertEquals('0.0000', $summary['vatable_sales']);
        $this->assertEquals('0.0000', $summary['vat_amount']);
    }

    /**
     * Test 5: Zero-rated sales report under the zero-rated bucket.
     */
    public function test_zero_rated_sales_report_under_zero_rated_bucket_correctly(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('80.0000', '0.0000', '0.0000', '80.0000', '0.0000', '0.0000', $saleDate);
        $this->createSaleItemRecord($sale->id, 'zero_rated', '80.0000', '0.0000', '0.0000', '80.0000', '0.0000');

        $summary = $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        $this->assertEquals('80.0000', $summary['gross_sales']);
        $this->assertEquals('80.0000', $summary['zero_rated_sales']);
        $this->assertEquals('0.0000', $summary['vatable_sales']);
        $this->assertEquals('0.0000', $summary['vat_amount']);
    }

    /**
     * Test 6: Non-VAT sales report under the non-vat bucket.
     */
    public function test_non_vat_sales_report_under_non_vat_bucket_correctly(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('120.0000', '0.0000', '0.0000', '0.0000', '120.0000', '0.0000', $saleDate);
        $this->createSaleItemRecord($sale->id, 'non_vat', '120.0000', '0.0000', '0.0000', '0.0000', '120.0000');

        $summary = $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        $this->assertEquals('120.0000', $summary['gross_sales']);
        $this->assertEquals('120.0000', $summary['non_vat_sales']);
        $this->assertEquals('0.0000', $summary['vatable_sales']);
        $this->assertEquals('0.0000', $summary['vat_amount']);
    }

    /**
     * Test 7: Mixed categories report totals match stored sale and sale_item fields.
     */
    public function test_mixed_category_report_totals_match_stored_fields_exactly(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        // Mixed sale total = 550.00 ( Vatable 200 gross -> 178.5714 net, 21.4286 VAT; Exempt 150; Zero-Rated 80; Non-VAT 120 )
        $sale = $this->createSaleRecord('550.0000', '178.5714', '150.0000', '80.0000', '120.0000', '21.4286', $saleDate);
        
        $this->createSaleItemRecord($sale->id, 'vatable', '200.0000', '178.5714', '0.0000', '0.0000', '0.0000');
        $this->createSaleItemRecord($sale->id, 'vat_exempt', '150.0000', '0.0000', '150.0000', '0.0000', '0.0000');
        $this->createSaleItemRecord($sale->id, 'zero_rated', '80.0000', '0.0000', '0.0000', '80.0000', '0.0000');
        $this->createSaleItemRecord($sale->id, 'non_vat', '120.0000', '0.0000', '0.0000', '0.0000', '120.0000');

        $summary = $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        $this->assertEquals('550.0000', $summary['gross_sales']);
        $this->assertEquals('178.5714', $summary['vatable_sales']);
        $this->assertEquals('150.0000', $summary['vat_exempt_sales']);
        $this->assertEquals('80.0000', $summary['zero_rated_sales']);
        $this->assertEquals('120.0000', $summary['non_vat_sales']);
        $this->assertEquals('21.4286', $summary['vat_amount']);
    }

    /**
     * Test 8: Export, if present, includes correct bucket columns.
     */
    public function test_csv_export_package_includes_correct_bucket_columns_and_data(): void
    {
        $packageService = app(\App\Services\Tax\ComplianceExportPackageService::class);
        $csvService = app(\App\Services\Tax\ComplianceCsvExportService::class);

        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('100.0000', '89.2857', '0.0000', '0.0000', '0.0000', '10.7143', $saleDate);
        $this->createSaleItemRecord($sale->id, 'vatable', '100.0000', '89.2857', '0.0000', '0.0000', '0.0000');

        $package = $packageService->preparePackage($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59', $this->branch->id, $this->user);

        // Assert contract structure contains correct keys
        $this->assertArrayHasKey('vatable_sales', $package['summary']);
        $this->assertArrayHasKey('vat_exempt_sales', $package['summary']);
        $this->assertArrayHasKey('zero_rated_sales', $package['summary']);
        $this->assertArrayHasKey('non_vat_sales', $package['summary']);
        $this->assertArrayHasKey('vat_amount', $package['summary']);

        $csv = $csvService->generate($package);

        // Verify CSV content contains headers and accurate inclusive metric strings
        $this->assertStringContainsString('gross_sales,100.0000', $csv);
        $this->assertStringContainsString('vatable_sales,89.2857', $csv);
        $this->assertStringContainsString('vat_exempt_sales,0.0000', $csv);
        $this->assertStringContainsString('zero_rated_sales,0.0000', $csv);
        $this->assertStringContainsString('non_vat_sales,0.0000', $csv);
        $this->assertStringContainsString('vat_amount,10.7143', $csv);
    }

    /**
     * Test 9: Read-only query execution does not mutate database records.
     */
    public function test_read_only_query_does_not_mutate_any_pos_or_inventory_records(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('100.0000', '89.2857', '0.0000', '0.0000', '0.0000', '10.7143', $saleDate);
        $this->createSaleItemRecord($sale->id, 'vatable', '100.0000', '89.2857', '0.0000', '0.0000', '0.0000');

        $salesCount = Sale::count();
        $itemsCount = SaleItem::count();
        
        $paymentsCount = \Illuminate\Support\Facades\Schema::hasTable('payments') ? \DB::table('payments')->count() : 0;
        $movementsCount = \Illuminate\Support\Facades\Schema::hasTable('inventory_movements') ? \DB::table('inventory_movements')->count() : 0;
        $outboxCount = \Illuminate\Support\Facades\Schema::hasTable('accounting_outbox') ? \DB::table('accounting_outbox')->count() : 0;

        // Perform read aggregation
        $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        $this->assertEquals($salesCount, Sale::count());
        $this->assertEquals($itemsCount, SaleItem::count());
        $this->assertEquals($paymentsCount, \Illuminate\Support\Facades\Schema::hasTable('payments') ? \DB::table('payments')->count() : 0);
        $this->assertEquals($movementsCount, \Illuminate\Support\Facades\Schema::hasTable('inventory_movements') ? \DB::table('inventory_movements')->count() : 0);
        $this->assertEquals($outboxCount, \Illuminate\Support\Facades\Schema::hasTable('accounting_outbox') ? \DB::table('accounting_outbox')->count() : 0);
    }

    /**
     * Test 10: SC/PWD statutory discount totals are not synthesized or invented when none exist.
     */
    public function test_statutory_discounts_are_not_falsely_synthesized_when_none_exist(): void
    {
        $saleDate = '2026-05-17 12:00:00';
        $sale = $this->createSaleRecord('100.0000', '89.2857', '0.0000', '0.0000', '0.0000', '10.7143', $saleDate);
        $this->createSaleItemRecord($sale->id, 'vatable', '100.0000', '89.2857', '0.0000', '0.0000', '0.0000');

        $summary = $this->service->summarize($this->tenant->id, '2026-05-17 00:00:00', '2026-05-17 23:59:59');

        // Confirm discount remains strictly 0.0000 and contains_statutory_discount has no side effects
        $this->assertEquals('0.0000', $summary['statutory_discount_amount']);
    }

    protected function createSaleRecord(
        string $gross,
        string $vatable,
        string $exempt,
        string $zeroRated,
        string $nonVat,
        string $vatAmount,
        string $reportingBasisAt
    ): Sale {
        return Sale::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => 'CSALE-' . strtoupper(Str::random(6)),
            'status' => 'paid',
            'subtotal' => $gross,
            'tax_total' => $vatAmount,
            'discount_total' => '0.0000',
            'total' => $gross,
            'gross_sales_amount' => $gross,
            'vatable_sales_amount' => $vatable,
            'vat_exempt_sales_amount' => $exempt,
            'zero_rated_sales_amount' => $zeroRated,
            'non_vat_sales_amount' => $nonVat,
            'vat_amount' => $vatAmount,
            'statutory_discount_total' => '0.0000',
            'commercial_discount_total' => '0.0000',
            'other_adjustment_total' => '0.0000',
            'contains_statutory_discount' => false,
            'reporting_basis_at' => $reportingBasisAt,
            'confirmed_at' => $reportingBasisAt,
        ]);
    }

    protected function createSaleItemRecord(
        string $saleId,
        string $taxBucket,
        string $netAmount,
        string $vatableAmount,
        string $vatExemptAmount,
        string $zeroRatedAmount,
        string $nonVatAmount
    ): void {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'selling_price' => $netAmount,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);

        SaleItem::insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
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
            'tax_rate' => $taxBucket === 'vatable' ? '12.0000' : '0.0000',
            'tax_amount' => $taxBucket === 'vatable' ? bcsub($netAmount, $vatableAmount, 4) : '0.0000',
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
}
