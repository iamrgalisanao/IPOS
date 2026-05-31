<?php

namespace App\Services\POS;

use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleReceiptPrint;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Support\Carbon;

class EJournalExportService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Export electronic journal as a pipe-delimited string.
     */
    public function export(array $filters): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        if (!$tenantId) {
            throw new \RuntimeException('Tenant context is required for e-journal export.');
        }

        $branchId = $filters['branch_id'] ?? null;
        $profileId = $filters['sales_machine_profile_id'] ?? null;
        
        $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : now()->startOfDay();
        $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : now()->endOfDay();

        $records = [];

        // 1. Fetch Sales (includes standard and training mode)
        $salesQuery = Sale::where('tenant_id', $tenantId)
            ->whereBetween('invoice_issued_at', [$dateFrom, $dateTo]);

        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
        }
        if ($profileId) {
            $salesQuery->where('sales_machine_profile_id', $profileId);
        }

        $sales = $salesQuery->with(['user', 'salesMachineProfile'])->get();

        foreach ($sales as $sale) {
            $type = $sale->is_training_mode ? 'TRAINING_SALE' : ($sale->status === 'voided' ? 'VOID' : 'SALE');
            
            $records[] = [
                'timestamp' => $sale->invoice_issued_at->toIso8601String(),
                'type' => $type,
                'invoice_number' => $sale->principal_invoice_number ?: $sale->sale_number,
                'cashier' => $sale->user?->name ?? 'System',
                'gross' => number_format((float)$sale->gross_sales_amount, 4, '.', ''),
                'vatable' => number_format((float)$sale->vatable_sales_amount, 4, '.', ''),
                'exempt' => number_format((float)$sale->vat_exempt_sales_amount, 4, '.', ''),
                'zero_rated' => number_format((float)$sale->zero_rated_sales_amount, 4, '.', ''),
                'non_vat' => number_format((float)$sale->non_vat_sales_amount, 4, '.', ''),
                'vat_amount' => number_format((float)$sale->vat_amount, 4, '.', ''),
                'stat_discount' => number_format((float)$sale->statutory_discount_total, 4, '.', ''),
                'comm_discount' => number_format((float)$sale->commercial_discount_total, 4, '.', ''),
                'net' => number_format((float)$sale->total, 4, '.', ''),
                'status' => $sale->status,
                'is_training' => $sale->is_training_mode ? 'TRUE' : 'FALSE',
            ];
        }

        // 2. Fetch Refunds
        $refundsQuery = SaleRefund::where('tenant_id', $tenantId)
            ->whereBetween('refunded_at', [$dateFrom, $dateTo]);

        if ($branchId) {
            $refundsQuery->where('branch_id', $branchId);
        }

        $refunds = $refundsQuery->with(['sale', 'sale.user'])->get();

        foreach ($refunds as $refund) {
            // Apply profile_id filter via associated sale if profile_id filter is active
            if ($profileId && $refund->sale?->sales_machine_profile_id !== $profileId) {
                continue;
            }

            $isTraining = ($refund->sale?->is_training_mode ?? false);
            $type = $isTraining ? 'TRAINING_REFUND' : 'REFUND';

            $records[] = [
                'timestamp' => $refund->refunded_at->toIso8601String(),
                'type' => $type,
                'invoice_number' => $refund->refund_number,
                'cashier' => $refund->sale?->user?->name ?? 'System',
                'gross' => number_format(-abs((float)$refund->refund_total), 4, '.', ''),
                'vatable' => '0.0000',
                'exempt' => '0.0000',
                'zero_rated' => '0.0000',
                'non_vat' => '0.0000',
                'vat_amount' => '0.0000',
                'stat_discount' => '0.0000',
                'comm_discount' => '0.0000',
                'net' => number_format(-abs((float)$refund->refund_total), 4, '.', ''),
                'status' => 'refunded',
                'is_training' => $isTraining ? 'TRUE' : 'FALSE',
            ];
        }

        // 3. Fetch Reprints
        $reprintsQuery = SaleReceiptPrint::where('tenant_id', $tenantId)
            ->where('is_reprint', true)
            ->whereBetween('printed_at', [$dateFrom, $dateTo]);

        if ($branchId) {
            $reprintsQuery->where('branch_id', $branchId);
        }

        $reprints = $reprintsQuery->with(['sale', 'user'])->get();

        foreach ($reprints as $reprint) {
            if ($profileId && $reprint->sale?->sales_machine_profile_id !== $profileId) {
                continue;
            }

            $isTraining = ($reprint->sale?->is_training_mode ?? false);
            $type = $isTraining ? 'TRAINING_REPRINT' : 'REPRINT';

            $records[] = [
                'timestamp' => $reprint->printed_at->toIso8601String(),
                'type' => $type,
                'invoice_number' => $reprint->sale?->principal_invoice_number ?: 'UNKNOWN',
                'cashier' => $reprint->user?->name ?? 'System',
                'gross' => '0.0000',
                'vatable' => '0.0000',
                'exempt' => '0.0000',
                'zero_rated' => '0.0000',
                'non_vat' => '0.0000',
                'vat_amount' => '0.0000',
                'stat_discount' => '0.0000',
                'comm_discount' => '0.0000',
                'net' => '0.0000',
                'status' => 'printed',
                'is_training' => $isTraining ? 'TRUE' : 'FALSE',
            ];
        }

        // Sort records chronologically
        usort($records, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        // Build output with internal tamper-evident hashes
        $lines = [];
        $lines[] = 'Timestamp|Record Type|Invoice Number|Cashier|Gross Amount|VATable Sales|VAT Exempt Sales|Zero Rated Sales|Non-VAT Sales|VAT Amount|Statutory Discount|Commercial Discount|Net Total|Status|Training?|TAMPER-EVIDENT HASH';

        foreach ($records as $record) {
            $fields = [
                $record['timestamp'],
                $record['type'],
                $record['invoice_number'],
                $record['cashier'],
                $record['gross'],
                $record['vatable'],
                $record['exempt'],
                $record['zero_rated'],
                $record['non_vat'],
                $record['vat_amount'],
                $record['stat_discount'],
                $record['comm_discount'],
                $record['net'],
                $record['status'],
                $record['is_training'],
            ];
            
            $lineWithoutHash = implode('|', $fields);
            $hash = hash_hmac('sha256', $lineWithoutHash, 'ipos_ejournal_compliance_key');
            $lines[] = $lineWithoutHash . '|' . $hash;
        }

        return implode("\n", $lines);
    }

    /**
     * Export electronic journal to a file descriptor, streaming records to avoid memory exhaustion.
     * Returns an array with file metadata: ['file_size' => int, 'checksum' => string, 'row_count' => int]
     */
    public function exportToFile(array $filters, string $absolutePath): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        if (!$tenantId) {
            throw new \RuntimeException('Tenant context is required for e-journal export.');
        }

        $branchId = $filters['branch_id'] ?? null;
        $profileId = $filters['sales_machine_profile_id'] ?? null;
        
        $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : now()->startOfDay();
        $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : now()->endOfDay();

        $records = [];

        // 1. Fetch Sales (includes standard and training mode)
        $salesQuery = Sale::where('tenant_id', $tenantId)
            ->whereBetween('invoice_issued_at', [$dateFrom, $dateTo]);

        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
        }
        if ($profileId) {
            $salesQuery->where('sales_machine_profile_id', $profileId);
        }

        // Chunking the retrieval to avoid memory bloat
        $salesQuery->with(['user', 'salesMachineProfile'])->chunkById(500, function ($sales) use (&$records) {
            foreach ($sales as $sale) {
                $type = $sale->is_training_mode ? 'TRAINING_SALE' : ($sale->status === 'voided' ? 'VOID' : 'SALE');
                
                $records[] = [
                    'timestamp' => $sale->invoice_issued_at->toIso8601String(),
                    'type' => $type,
                    'invoice_number' => $sale->principal_invoice_number ?: $sale->sale_number,
                    'cashier' => $sale->user?->name ?? 'System',
                    'gross' => number_format((float)$sale->gross_sales_amount, 4, '.', ''),
                    'vatable' => number_format((float)$sale->vatable_sales_amount, 4, '.', ''),
                    'exempt' => number_format((float)$sale->vat_exempt_sales_amount, 4, '.', ''),
                    'zero_rated' => number_format((float)$sale->zero_rated_sales_amount, 4, '.', ''),
                    'non_vat' => number_format((float)$sale->non_vat_sales_amount, 4, '.', ''),
                    'vat_amount' => number_format((float)$sale->vat_amount, 4, '.', ''),
                    'stat_discount' => number_format((float)$sale->statutory_discount_total, 4, '.', ''),
                    'comm_discount' => number_format((float)$sale->commercial_discount_total, 4, '.', ''),
                    'net' => number_format((float)$sale->total, 4, '.', ''),
                    'status' => $sale->status,
                    'is_training' => $sale->is_training_mode ? 'TRUE' : 'FALSE',
                ];
            }
        });

        // 2. Fetch Refunds
        $refundsQuery = SaleRefund::where('tenant_id', $tenantId)
            ->whereBetween('refunded_at', [$dateFrom, $dateTo]);

        if ($branchId) {
            $refundsQuery->where('branch_id', $branchId);
        }

        $refundsQuery->with(['sale', 'sale.user'])->chunkById(500, function ($refunds) use (&$records, $profileId) {
            foreach ($refunds as $refund) {
                if ($profileId && $refund->sale?->sales_machine_profile_id !== $profileId) {
                    continue;
                }

                $isTraining = ($refund->sale?->is_training_mode ?? false);
                $type = $isTraining ? 'TRAINING_REFUND' : 'REFUND';

                $records[] = [
                    'timestamp' => $refund->refunded_at->toIso8601String(),
                    'type' => $type,
                    'invoice_number' => $refund->refund_number,
                    'cashier' => $refund->sale?->user?->name ?? 'System',
                    'gross' => number_format(-abs((float)$refund->refund_total), 4, '.', ''),
                    'vatable' => '0.0000',
                    'exempt' => '0.0000',
                    'zero_rated' => '0.0000',
                    'non_vat' => '0.0000',
                    'vat_amount' => '0.0000',
                    'stat_discount' => '0.0000',
                    'comm_discount' => '0.0000',
                    'net' => number_format(-abs((float)$refund->refund_total), 4, '.', ''),
                    'status' => 'refunded',
                    'is_training' => $isTraining ? 'TRUE' : 'FALSE',
                ];
            }
        });

        // 3. Fetch Reprints
        $reprintsQuery = SaleReceiptPrint::where('tenant_id', $tenantId)
            ->where('is_reprint', true)
            ->whereBetween('printed_at', [$dateFrom, $dateTo]);

        if ($branchId) {
            $reprintsQuery->where('branch_id', $branchId);
        }

        $reprintsQuery->with(['sale', 'user'])->chunkById(500, function ($reprints) use (&$records, $profileId) {
            foreach ($reprints as $reprint) {
                if ($profileId && $reprint->sale?->sales_machine_profile_id !== $profileId) {
                    continue;
                }

                $isTraining = ($reprint->sale?->is_training_mode ?? false);
                $type = $isTraining ? 'TRAINING_REPRINT' : 'REPRINT';

                $records[] = [
                    'timestamp' => $reprint->printed_at->toIso8601String(),
                    'type' => $type,
                    'invoice_number' => $reprint->sale?->principal_invoice_number ?: 'UNKNOWN',
                    'cashier' => $reprint->user?->name ?? 'System',
                    'gross' => '0.0000',
                    'vatable' => '0.0000',
                    'exempt' => '0.0000',
                    'zero_rated' => '0.0000',
                    'non_vat' => '0.0000',
                    'vat_amount' => '0.0000',
                    'stat_discount' => '0.0000',
                    'comm_discount' => '0.0000',
                    'net' => '0.0000',
                    'status' => 'printed',
                    'is_training' => $isTraining ? 'TRUE' : 'FALSE',
                ];
            }
        });

        // Sort records chronologically
        usort($records, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        // Write to file incrementally
        $fileHandle = fopen($absolutePath, 'w');
        if (!$fileHandle) {
            throw new \RuntimeException("Could not open file for writing: $absolutePath");
        }

        $header = 'Timestamp|Record Type|Invoice Number|Cashier|Gross Amount|VATable Sales|VAT Exempt Sales|Zero Rated Sales|Non-VAT Sales|VAT Amount|Statutory Discount|Commercial Discount|Net Total|Status|Training?|TAMPER-EVIDENT HASH' . "\n";
        fwrite($fileHandle, $header);

        $rowCount = 0;
        $hashContext = hash_init('sha256');
        hash_update($hashContext, rtrim($header, "\n")); // Update checksum with header

        foreach ($records as $record) {
            $fields = [
                $record['timestamp'],
                $record['type'],
                $record['invoice_number'],
                $record['cashier'],
                $record['gross'],
                $record['vatable'],
                $record['exempt'],
                $record['zero_rated'],
                $record['non_vat'],
                $record['vat_amount'],
                $record['stat_discount'],
                $record['comm_discount'],
                $record['net'],
                $record['status'],
                $record['is_training'],
            ];
            
            $lineWithoutHash = implode('|', $fields);
            $rowHash = hash_hmac('sha256', $lineWithoutHash, 'ipos_ejournal_compliance_key');
            $line = $lineWithoutHash . '|' . $rowHash . "\n";
            
            fwrite($fileHandle, $line);
            hash_update($hashContext, rtrim($line, "\n")); // Update overall file checksum
            $rowCount++;
        }

        fclose($fileHandle);

        return [
            'file_size' => filesize($absolutePath),
            'checksum' => hash_final($hashContext),
            'row_count' => $rowCount,
        ];
    }
}
