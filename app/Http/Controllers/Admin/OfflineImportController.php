<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfflineSalesImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineImportController extends Controller
{
    /**
     * List offline imports with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OfflineSalesImport::query()
            ->with(['salesMachineProfile:id,profile_code,machine_identification_number']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->input('batch_id'));
        }

        if ($request->filled('batch_reference')) {
            $query->whereHas('batch', function ($q) use ($request) {
                $q->where('batch_reference', $request->input('batch_reference'));
            });
        }

        if ($request->filled('sales_machine_profile_id')) {
            $query->where('sales_machine_profile_id', $request->input('sales_machine_profile_id'));
        }

        if ($request->filled('submitted_from') && $request->filled('submitted_to')) {
            $query->whereBetween('submitted_at', [
                $request->input('submitted_from'),
                $request->input('submitted_to'),
            ]);
        }

        $imports = $query->orderBy('submitted_at', 'desc')->paginate(50);

        return response()->json($imports);
    }

    /**
     * Show detailed view of a specific offline import.
     */
    public function show(OfflineSalesImport $offlineSalesImport): JsonResponse
    {
        $offlineSalesImport->load([
            'salesMachineProfile:id,profile_code,machine_identification_number',
            'batch:id,batch_reference',
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
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
