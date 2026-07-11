<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfflineSalesImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OfflineImportController extends Controller
{
    /**
     * List offline imports with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OfflineSalesImport::query()
            ->with([
                'batch:id,batch_reference,status',
                'branch:id,name,branch_code',
                'reconciledSale',
                'reviewedBy:id,first_name,last_name',
                'salesMachineProfile:id,profile_code,terminal_identifier,machine_identification_number',
            ]);

        if ($request->filled('status')) {
            $statuses = array_filter(explode(',', (string) $request->input('status')));
            count($statuses) > 1
                ? $query->whereIn('status', $statuses)
                : $query->where('status', $request->input('status'));
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->input('batch_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        if ($request->filled('batch_reference')) {
            $query->whereHas('batch', function ($q) use ($request) {
                $q->where('batch_reference', $request->input('batch_reference'));
            });
        }

        if ($request->filled('sales_machine_profile_id')) {
            $query->where('sales_machine_profile_id', $request->input('sales_machine_profile_id'));
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            $query->where(function ($nested) use ($search) {
                $nested->where('offline_sequence_number', 'like', "%{$search}%")
                    ->orWhere('payload_hash', 'like', "%{$search}%")
                    ->orWhereHas('batch', fn ($batch) => $batch->where('batch_reference', 'like', "%{$search}%"))
                    ->orWhereHas('salesMachineProfile', function ($profile) use ($search) {
                        $profile->where('profile_code', 'like', "%{$search}%")
                            ->orWhere('terminal_identifier', 'like', "%{$search}%")
                            ->orWhere('machine_identification_number', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('submitted_from') && $request->filled('submitted_to')) {
            $query->whereBetween('submitted_at', [
                $request->input('submitted_from'),
                $request->input('submitted_to'),
            ]);
        }

        $imports = $query->orderBy('submitted_at', 'desc')
            ->paginate((int) $request->input('per_page', 25))
            ->through(fn (OfflineSalesImport $import) => $this->summarizeImport($import));

        return response()->json($imports);
    }

    /**
     * Show detailed view of a specific offline import.
     */
    public function show(OfflineSalesImport $offlineSalesImport): JsonResponse
    {
        $offlineSalesImport->load([
            'salesMachineProfile:id,profile_code,terminal_identifier,machine_identification_number',
            'batch:id,batch_reference',
            'branch:id,name,branch_code',
            'reconciledSale',
            'reviewedBy:id,first_name,last_name',
        ]);

        return response()->json([
            'id'                       => $offlineSalesImport->id,
            'status'                   => $offlineSalesImport->status,
            'offline_sequence_number'  => $offlineSalesImport->offline_sequence_number,
            'raw_payload'              => $offlineSalesImport->raw_payload,
            'server_recalculation'     => $offlineSalesImport->server_recalculation,
            'conflict_notes'           => $offlineSalesImport->conflict_notes,
            'rejection_reason'         => $offlineSalesImport->rejection_reason,
            'reviewed_by'              => $offlineSalesImport->reviewedBy ? [
                'id'         => $offlineSalesImport->reviewedBy->id,
                'first_name' => $offlineSalesImport->reviewedBy->first_name,
                'last_name'  => $offlineSalesImport->reviewedBy->last_name,
            ] : null,
            'reviewed_at'              => $offlineSalesImport->reviewed_at,
            'review_notes'             => $offlineSalesImport->review_notes,
            'batch_metadata'           => $offlineSalesImport->batch,
            'terminal_metadata'        => $offlineSalesImport->salesMachineProfile,
            'branch_metadata'          => $offlineSalesImport->branch,
            'reconciled_sale'          => $offlineSalesImport->reconciledSale ? [
                'id'             => $offlineSalesImport->reconciledSale->id,
                'invoice_number' => $offlineSalesImport->reconciledSale->principal_invoice_number
                    ?? $offlineSalesImport->reconciledSale->sale_number
                    ?? null,
                'receipt_number' => $offlineSalesImport->reconciledSale->sale_number ?? null,
                'total_amount'   => $offlineSalesImport->reconciledSale->total ?? null,
            ] : null,
        ]);
    }

    /**
     * Transition the status of an offline import for review.
     */
    public function review(Request $request, OfflineSalesImport $offlineSalesImport): JsonResponse
    {
        $validated = $request->validate([
            'status'       => ['required', 'in:hold,override_approved,conflict'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $currentStatus = $offlineSalesImport->status;
        $newStatus = $validated['status'];

        // Allowed transitions:
        // conflict -> hold
        // conflict -> override_approved
        // hold -> override_approved
        // hold -> conflict

        $allowedTransitions = [
            OfflineSalesImport::STATUS_CONFLICT => [
                OfflineSalesImport::STATUS_HOLD,
                OfflineSalesImport::STATUS_OVERRIDE_APPROVED,
            ],
            OfflineSalesImport::STATUS_HOLD => [
                OfflineSalesImport::STATUS_OVERRIDE_APPROVED,
                OfflineSalesImport::STATUS_CONFLICT,
            ],
        ];

        if (!isset($allowedTransitions[$currentStatus]) || !in_array($newStatus, $allowedTransitions[$currentStatus])) {
            return response()->json([
                'message' => 'Invalid state transition from ' . $currentStatus . ' to ' . $newStatus,
            ], 422);
        }

        $offlineSalesImport->update([
            'status'              => $newStatus,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at'         => now(),
            'review_notes'        => $validated['review_notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Review status updated successfully.',
            'status'  => $newStatus,
            'import'  => $this->summarizeImport($offlineSalesImport->fresh([
                'batch:id,batch_reference,status',
                'branch:id,name,branch_code',
                'reconciledSale',
                'reviewedBy:id,first_name,last_name',
                'salesMachineProfile:id,profile_code,terminal_identifier,machine_identification_number',
            ])),
        ]);
    }

    /**
     * Post an eligible offline import into an official Sale.
     */
    public function postImport(OfflineSalesImport $offlineSalesImport, \App\Services\POS\OfflineSync\OfflineReconciliationService $reconciliationService): JsonResponse
    {
        try {
            $sale = $reconciliationService->reconcileImport($offlineSalesImport);

            return response()->json([
                'message' => 'Offline import posted successfully.',
                'sale_id' => $sale->id,
                'import'  => $this->summarizeImport($offlineSalesImport->fresh([
                    'batch:id,batch_reference,status',
                    'branch:id,name,branch_code',
                    'reconciledSale',
                    'reviewedBy:id,first_name,last_name',
                    'salesMachineProfile:id,profile_code,terminal_identifier,machine_identification_number',
                ])),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function summarizeImport(OfflineSalesImport $import): array
    {
        $rawPayload = is_array($import->raw_payload) ? $import->raw_payload : [];
        $serverRecalculation = is_array($import->server_recalculation) ? $import->server_recalculation : [];

        return [
            'id' => $import->id,
            'status' => $import->status,
            'offline_sequence_number' => $import->offline_sequence_number,
            'payload_hash' => $import->payload_hash,
            'submitted_at' => optional($import->submitted_at)->toIso8601String(),
            'reconciled_at' => optional($import->reconciled_at)->toIso8601String(),
            'conflict_notes' => $import->conflict_notes,
            'rejection_reason' => $import->rejection_reason,
            'review_notes' => $import->review_notes,
            'reviewed_at' => optional($import->reviewed_at)->toIso8601String(),
            'client_total' => Arr::get($rawPayload, 'client_total'),
            'server_total' => Arr::get($serverRecalculation, 'server_total'),
            'local_transaction_reference' => Arr::get($rawPayload, 'local_transaction_reference'),
            'payment_method' => Arr::get($rawPayload, 'payment_method'),
            'batch' => $import->batch ? [
                'id' => $import->batch->id,
                'batch_reference' => $import->batch->batch_reference,
                'status' => $import->batch->status,
            ] : null,
            'branch' => $import->branch ? [
                'id' => $import->branch->id,
                'name' => $import->branch->name,
                'branch_code' => $import->branch->branch_code,
            ] : null,
            'terminal' => $import->salesMachineProfile ? [
                'id' => $import->salesMachineProfile->id,
                'profile_code' => $import->salesMachineProfile->profile_code,
                'terminal_identifier' => $import->salesMachineProfile->terminal_identifier,
                'machine_identification_number' => $import->salesMachineProfile->machine_identification_number,
            ] : null,
            'reviewed_by' => $import->reviewedBy ? [
                'id' => $import->reviewedBy->id,
                'name' => trim(($import->reviewedBy->first_name ?? '') . ' ' . ($import->reviewedBy->last_name ?? '')),
            ] : null,
            'reconciled_sale' => $import->reconciledSale ? [
                'id' => $import->reconciledSale->id,
                'invoice_number' => $import->reconciledSale->principal_invoice_number
                    ?? $import->reconciledSale->sale_number
                    ?? null,
                'receipt_number' => $import->reconciledSale->sale_number ?? null,
                'total_amount' => $import->reconciledSale->total ?? null,
            ] : null,
        ];
    }
}
