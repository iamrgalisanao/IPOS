<?php

namespace Tests\Feature\Epic14;

use App\Services\Tax\ComplianceCsvExportService;
use Tests\TestCase;

class ComplianceCsvExportServiceTest extends TestCase
{
    protected ComplianceCsvExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceCsvExportService();
    }

    public function test_generate_returns_expected_csv_structure_with_all_sections(): void
    {
        $package = [
            'metadata' => [
                'generated_at' => '2026-05-13T10:00:00Z',
                'tenant_id' => 'tenant-123',
                'branch_id' => 'branch-456',
                'generated_by' => 'Test User',
            ],
            'filters' => [
                'date_from' => '2026-05-12',
                'date_to' => '2026-05-13',
            ],
            'summary' => [
                'gross_sales' => '1000.0000',
                'transaction_count' => 10,
            ],
            'notes' => [
                'Note 1',
                'Note 2',
            ],
        ];

        $csv = $this->service->generate($package);

        $this->assertStringContainsString('IPOS Compliance Export', $csv);
        
        $this->assertStringContainsString('Metadata', $csv);
        $this->assertStringContainsString('generated_at,2026-05-13T10:00:00Z', $csv);
        $this->assertStringContainsString('tenant_id,tenant-123', $csv);
        
        $this->assertStringContainsString('Filters', $csv);
        $this->assertStringContainsString('date_from,2026-05-12', $csv);
        
        $this->assertStringContainsString('Summary', $csv);
        $this->assertStringContainsString('Metric,Value', $csv);
        $this->assertStringContainsString('gross_sales,1000.0000', $csv);
        $this->assertStringContainsString('transaction_count,10', $csv);
        
        $this->assertStringContainsString('Notes', $csv);
        $this->assertStringContainsString('Note 1', $csv);
        $this->assertStringContainsString('Note 2', $csv);
    }

    public function test_generate_handles_escaping_and_formula_injection_safely(): void
    {
        $package = [
            'metadata' => [
                'formula' => '=SUM(A1:A10)',
                'plus' => '+123',
                'minus' => '-456',
                'at' => '@at',
                'comma' => 'Value, with comma',
                'quote' => 'Value with "quote"',
                'newline' => "Value with\nnewline",
            ],
        ];

        $csv = $this->service->generate($package);

        // Formula injection protection (prefixing with ')
        $this->assertStringContainsString("formula,'=SUM(A1:A10)", $csv);
        $this->assertStringContainsString("plus,'+123", $csv);
        $this->assertStringContainsString("minus,'-456", $csv);
        $this->assertStringContainsString("at,'@at", $csv);

        // CSV escaping (handled by fputcsv)
        $this->assertStringContainsString('comma,"Value, with comma"', $csv);
        $this->assertStringContainsString('quote,"Value with ""quote"""', $csv);
        $this->assertStringContainsString("newline,\"Value with\nnewline\"", $csv);
    }

    public function test_generate_handles_missing_sections_gracefully(): void
    {
        $package = [
            'metadata' => ['key' => 'value']
            // other sections missing
        ];

        $csv = $this->service->generate($package);

        $this->assertStringContainsString('Metadata', $csv);
        $this->assertStringNotContainsString('Filters', $csv);
        $this->assertStringNotContainsString('Summary', $csv);
        $this->assertStringNotContainsString('Notes', $csv);
    }

    public function test_generate_is_read_only_and_does_not_mutate_state(): void
    {
        $package = ['metadata' => ['key' => 'value']];
        $csv1 = $this->service->generate($package);
        $csv2 = $this->service->generate($package);

        $this->assertSame($csv1, $csv2);
    }
}
