<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\InventoryVarianceLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VarianceLogController extends Controller
{
    /**
     * Display a listing of inventory variance logs with filtering.
     */
    public function index(Request $request)
    {
        $query = InventoryVarianceLog::with(['branch', 'sale', 'product', 'ingredient']);

        $query = $this->applyFilters($query, $request);

        $logs = $query->latest()->paginate(25)->withQueryString();
        
        $branches = Branch::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Inventory/VarianceLogs/Index', [
            'logs' => $logs,
            'branches' => $branches,
            'filters' => $request->only(['start_date', 'end_date', 'branch_id', 'search']),
        ]);
    }

    /**
     * Export the variance logs as a CSV stream with formula-injection mitigation.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = InventoryVarianceLog::with(['branch', 'sale', 'product', 'ingredient']);
        $query = $this->applyFilters($query, $request);
        
        // Fetch ordered by latest
        $logs = $query->latest()->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inventory_variance_logs_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            
            // Add CSV Headers
            fputcsv($file, [
                'Date',
                'Branch',
                'Sale Number',
                'Parent Product SKU',
                'Parent Product Name',
                'Ingredient SKU',
                'Ingredient Name',
                'Required Qty',
                'Available Qty Before',
                'Shortage Qty',
                'Resulting Qty',
                'Unit',
                'Policy',
                'Reason'
            ]);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $this->escapeCsvValue($log->created_at?->toDateTimeString()),
                    $this->escapeCsvValue($log->branch?->name),
                    $this->escapeCsvValue($log->sale?->sale_number),
                    $this->escapeCsvValue($log->product?->sku),
                    $this->escapeCsvValue($log->product?->name),
                    $this->escapeCsvValue($log->ingredient?->sku),
                    $this->escapeCsvValue($log->ingredient?->name),
                    $this->escapeCsvValue($log->required_quantity),
                    $this->escapeCsvValue($log->available_quantity_before),
                    $this->escapeCsvValue($log->shortage_quantity),
                    $this->escapeCsvValue($log->resulting_quantity),
                    $this->escapeCsvValue($log->unit),
                    $this->escapeCsvValue($log->policy),
                    $this->escapeCsvValue($log->reason),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Apply filter request to variance logs query.
     */
    private function applyFilters($query, Request $request)
    {
        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', $request->start_date . ' 00:00:00');
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('ingredient', function ($iq) use ($search) {
                    $iq->where('name', 'like', "%{$search}%")
                       ->where('sku', 'like', "%{$search}%");
                })
                ->orWhereHas('product', function ($pq) use ($search) {
                    $pq->where('name', 'like', "%{$search}%")
                       ->orWhere('sku', 'like', "%{$search}%");
                })
                ->orWhereHas('sale', function ($sq) use ($search) {
                    $sq->where('sale_number', 'like', "%{$search}%");
                });
            });
        }

        return $query;
    }

    /**
     * Escape formula injection characters for CSV safety.
     */
    private function escapeCsvValue($value): string
    {
        if (is_null($value)) {
            return '';
        }
        $str = (string) $value;
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        return $str;
    }
}
