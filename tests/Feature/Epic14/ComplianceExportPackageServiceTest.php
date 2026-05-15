<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tax\ComplianceExportPackageService;
use App\Services\Tax\SalesTaxReportingQueryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ComplianceExportPackageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected ComplianceExportPackageService $service;
    protected $queryServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);

        $this->queryServiceMock = Mockery::mock(SalesTaxReportingQueryService::class);
        $this->service = new ComplianceExportPackageService($this->queryServiceMock);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Mockery::close();
        parent::tearDown();
    }

    public function test_prepare_package_returns_expected_contract_structure(): void
    {
        $dateFrom = '2026-05-12 00:00:00';
        $dateTo = '2026-05-12 23:59:59';
        
        $mockSummary = [
            'gross_sales' => '1000.0000',
            'net_sales' => '800.0000',
            'vatable_sales' => '714.2857',
            'vat_exempt_sales' => '100.0000',
            'zero_rated_sales' => '50.0000',
            'non_vat_sales' => '20.0000',
            'vat_amount' => '85.7143',
            'statutory_discount_amount' => '50.0000',
            'regular_discount_amount' => '20.0000',
            'void_adjustment_amount' => '100.0000',
            'refund_adjustment_amount' => '30.0000',
            'reversal_adjustment_amount' => '10.0000',
            'net_adjustment_amount' => '140.0000',
            'transaction_count' => 10,
            'refund_count' => 1,
            'void_count' => 2,
            'reversal_count' => 3,
            'reviewed_period_count' => 1,
            'locked_period_count' => 0,
            'has_reviewed_period' => true,
            'has_locked_period' => false,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        $this->queryServiceMock->shouldReceive('summarize')
            ->once()
            ->with($this->tenant->id, $dateFrom, $dateTo, $this->branch->id)
            ->andReturn($mockSummary);

        $package = $this->service->preparePackage(
            $this->tenant->id,
            $dateFrom,
            $dateTo,
            $this->branch->id,
            $this->user
        );

        $this->assertArrayHasKey('metadata', $package);
        $this->assertArrayHasKey('filters', $package);
        $this->assertArrayHasKey('summary', $package);
        $this->assertArrayHasKey('notes', $package);

        $this->assertSame($this->tenant->id, $package['metadata']['tenant_id']);
        $this->assertSame($this->branch->id, $package['metadata']['branch_id']);
        $this->assertSame($this->user->name, $package['metadata']['generated_by']);
        $this->assertSame('sales_tax_reporting_query_service', $package['metadata']['source']);

        $this->assertSame($dateFrom, $package['filters']['date_from']);
        $this->assertSame($dateTo, $package['filters']['date_to']);
        $this->assertSame($this->branch->id, $package['filters']['branch_id']);

        $this->assertSame('1000.0000', $package['summary']['gross_sales']);
        $this->assertSame('800.0000', $package['summary']['net_sales']);
        $this->assertSame(10, $package['summary']['transaction_count']);

        $this->assertCount(2, $package['notes']);
        $this->assertStringContainsString('not represent standalone BIR certification', $package['notes'][1]);
    }

    public function test_prepare_package_redacts_sensitive_data(): void
    {
        $dateFrom = '2026-05-12 00:00:00';
        $dateTo = '2026-05-12 23:59:59';
        
        $mockSummary = [
            'gross_sales' => '100.0000',
            'net_sales' => '100.0000',
            'vatable_sales' => '0.0000',
            'vat_exempt_sales' => '0.0000',
            'zero_rated_sales' => '0.0000',
            'non_vat_sales' => '0.0000',
            'vat_amount' => '0.0000',
            'statutory_discount_amount' => '0.0000',
            'regular_discount_amount' => '0.0000',
            'void_adjustment_amount' => '0.0000',
            'refund_adjustment_amount' => '0.0000',
            'reversal_adjustment_amount' => '0.0000',
            'net_adjustment_amount' => '0.0000',
            'transaction_count' => 1,
            // Imagine some internal data leaked into summary (it shouldn't, but we check redaction in package)
            'raw_payload' => ['sensitive' => 'data'],
            'token' => 'secret-token',
        ];

        $this->queryServiceMock->shouldReceive('summarize')->andReturn($mockSummary);

        $package = $this->service->preparePackage($this->tenant->id, $dateFrom, $dateTo);

        $this->assertArrayNotHasKey('raw_payload', $package);
        $this->assertArrayNotHasKey('token', $package);
        $this->assertArrayNotHasKey('raw_payload', $package['summary']);
        $this->assertArrayNotHasKey('token', $package['summary']);
        
        // Ensure only allowed keys exist in summary
        $allowedSummaryKeys = [
            'gross_sales', 'net_sales', 'vatable_sales', 'vat_exempt_sales',
            'zero_rated_sales', 'non_vat_sales', 'vat_amount',
            'statutory_discount_amount', 'regular_discount_amount',
            'void_adjustment_amount', 'refund_adjustment_amount',
            'reversal_adjustment_amount', 'net_adjustment_amount',
            'transaction_count'
        ];
        $this->assertEqualsCanonicalizing($allowedSummaryKeys, array_keys($package['summary']));
    }

    public function test_prepare_package_does_not_mutate_database(): void
    {
        $dateFrom = '2026-05-12 00:00:00';
        $dateTo = '2026-05-12 23:59:59';
        
        $this->queryServiceMock->shouldReceive('summarize')->andReturn([
            'gross_sales' => '0.0000',
            'net_sales' => '0.0000',
            'vatable_sales' => '0.0000',
            'vat_exempt_sales' => '0.0000',
            'zero_rated_sales' => '0.0000',
            'non_vat_sales' => '0.0000',
            'vat_amount' => '0.0000',
            'statutory_discount_amount' => '0.0000',
            'regular_discount_amount' => '0.0000',
            'void_adjustment_amount' => '0.0000',
            'refund_adjustment_amount' => '0.0000',
            'reversal_adjustment_amount' => '0.0000',
            'net_adjustment_amount' => '0.0000',
            'transaction_count' => 0,
        ]);

        $tenantCount = Tenant::count();
        $branchCount = Branch::count();
        $userCount = User::count();

        $this->service->preparePackage($this->tenant->id, $dateFrom, $dateTo);

        $this->assertSame($tenantCount, Tenant::count());
        $this->assertSame($branchCount, Branch::count());
        $this->assertSame($userCount, User::count());
    }
}
