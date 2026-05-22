<?php

namespace App\Services\Procurement;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;

class SupplierInvoiceMatchingService
{
    /**
     * Executes the 3-Way Matching reconciliation engine for a Supplier Invoice.
     *
     * Rules evaluated:
     * 1. Quantity Rule: Qty Billed <= Qty Received <= Qty Ordered
     * 2. Unit Cost Rule: Cost Billed <= Cost Ordered (or within unit price tolerance)
     * 3. Cumulative price variance: Total Billed vs Expected Billed (using PO Cost) is within absolute tolerance.
     *
     * @param SupplierInvoice $invoice
     * @param float $priceTolerance Default 1.0% (0.01)
     * @param float $absoluteTolerance Default 5.00 (PHP/units)
     * @return SupplierInvoice
     */
    public function match(
        SupplierInvoice $invoice,
        float $priceTolerance = 0.01,
        float $absoluteTolerance = 5.00
    ): SupplierInvoice {
        $lines = $invoice->lines()
            ->with(['purchaseReceivingLine.purchaseOrderLine'])
            ->get();

        $discrepancies = [];
        $lineResults = [];
        
        $billedTotal = 0.0000;
        $expectedTotal = 0.0000;
        $cumulativeVariance = 0.0000;

        if ($lines->isEmpty()) {
            $discrepancies[] = [
                'type' => 'empty_invoice',
                'message' => 'The supplier invoice contains no line items to match.',
            ];
        }

        foreach ($lines as $line) {
            $lineDiscrepancies = [];
            $receivingLine = $line->purchaseReceivingLine;
            $poLine = $receivingLine ? $receivingLine->purchaseOrderLine : null;

            $qtyBilled = (float) $line->quantity_billed;
            $qtyReceived = $receivingLine ? (float) $receivingLine->received_quantity : 0.0000;
            $qtyOrdered = $poLine ? (float) $poLine->ordered_quantity : 0.0000;

            $costBilled = (float) $line->unit_cost_billed;
            $costOrdered = $poLine ? (float) $poLine->unit_cost : 0.0000;

            // Update financial aggregations
            $lineBilledTotal = $qtyBilled * $costBilled;
            $lineExpectedTotal = $qtyBilled * $costOrdered;
            
            $billedTotal += $lineBilledTotal;
            $expectedTotal += $lineExpectedTotal;
            $cumulativeVariance += abs($lineBilledTotal - $lineExpectedTotal);

            // 1. Check references
            if (!$receivingLine) {
                $lineDiscrepancies[] = [
                    'type' => 'unlinked_line',
                    'message' => "Invoice line is not linked to any goods receipt voucher (receiving line).",
                ];
            } elseif (!$poLine) {
                $lineDiscrepancies[] = [
                    'type' => 'missing_po_line',
                    'message' => "Goods receipt line references a missing or deleted purchase order line.",
                ];
            } else {
                // 2. Quantity Rule: Qty Billed <= Qty Received
                if ($qtyBilled > $qtyReceived) {
                    $lineDiscrepancies[] = [
                        'type' => 'over_billed_quantity',
                        'message' => "Billed quantity ({$qtyBilled}) exceeds received quantity ({$qtyReceived}).",
                        'billed_qty' => $qtyBilled,
                        'received_qty' => $qtyReceived,
                    ];
                }

                // 3. Unit Cost Rule: Cost Billed vs Cost Ordered
                if ($costBilled > $costOrdered) {
                    $diff = $costBilled - $costOrdered;
                    $variancePercent = $costOrdered > 0 ? ($diff / $costOrdered) : 1.0;

                    if ($variancePercent > $priceTolerance) {
                        $lineDiscrepancies[] = [
                            'type' => 'price_variance',
                            'message' => sprintf(
                                "Billed unit cost (%.4f) exceeds ordered cost (%.4f) by %.2f%%, which is above tolerance (%.2f%%).",
                                $costBilled,
                                $costOrdered,
                                $variancePercent * 100,
                                $priceTolerance * 100
                            ),
                            'billed_cost' => $costBilled,
                            'ordered_cost' => $costOrdered,
                            'variance_percent' => $variancePercent,
                        ];
                    }
                }
            }

            if (!empty($lineDiscrepancies)) {
                $discrepancies = array_merge($discrepancies, array_map(function ($d) use ($line) {
                    return array_merge(['line_id' => $line->id, 'product_id' => $line->product_id], $d);
                }, $lineDiscrepancies));
            }

            $lineResults[] = [
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'quantity_billed' => $qtyBilled,
                'quantity_received' => $qtyReceived,
                'quantity_ordered' => $qtyOrdered,
                'unit_cost_billed' => $costBilled,
                'unit_cost_ordered' => $costOrdered,
                'is_matched' => empty($lineDiscrepancies),
                'discrepancies' => $lineDiscrepancies,
            ];
        }

        // 4. Cumulative price variance threshold check
        if ($cumulativeVariance > $absoluteTolerance) {
            $discrepancies[] = [
                'type' => 'cumulative_price_variance',
                'message' => sprintf(
                    "Total absolute price variance (%.4f) exceeds cumulative threshold tolerance (%.4f).",
                    $cumulativeVariance,
                    $absoluteTolerance
                ),
                'billed_total' => $billedTotal,
                'expected_total' => $expectedTotal,
                'total_variance' => $cumulativeVariance,
                'threshold' => $absoluteTolerance,
            ];
        }

        $status = empty($discrepancies) ? SupplierInvoice::STATUS_MATCHED : SupplierInvoice::STATUS_DISCREPANT;

        $invoice->forceFill([
            'match_status' => $status,
            'matching_metadata' => [
                'is_matched' => $status === SupplierInvoice::STATUS_MATCHED,
                'match_status' => $status,
                'matched_at' => now()->toIso8601String(),
                'line_results' => $lineResults,
                'discrepancies' => $discrepancies,
                'invoice_summary' => [
                    'billed_total' => round($billedTotal, 4),
                    'expected_total' => round($expectedTotal, 4),
                    'total_variance' => round($cumulativeVariance, 4),
                ]
            ]
        ])->save();

        return $invoice;
    }
}
