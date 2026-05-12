<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingOutbox;
use App\Services\Accounting\AccountingOutboxQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AccountingOutboxController extends Controller
{
    public function __construct(
        protected AccountingOutboxQueryService $queryService
    ) {}

    /**
     * List accounting outbox records.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('view_sync_dashboard')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $filters = $request->only([
            'event_type', 
            'sync_status', 
            'source_type', 
            'branch_id', 
            'date_from', 
            'date_to'
        ]);

        $results = $this->queryService->query($filters, $request->user())->paginate($request->get('per_page', 50));

        $results->through(fn (AccountingOutbox $record) => $this->queryService->serializeSummary($record));

        return response()->json($results);
    }

    /**
     * Show a specific outbox record with its payload.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (!$request->user()->hasPermission('view_sync_dashboard')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $record = $this->queryService->find($id, $request->user());

        if (!$record) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return response()->json($this->queryService->serializeDetail($record->load('attempts'), true));
    }
}
