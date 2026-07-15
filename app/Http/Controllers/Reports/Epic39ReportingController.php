<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CustomerFinancialAccount;
use App\Services\Reports\Epic39ReportingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Epic39ReportingController extends Controller
{
    public function __construct(
        private readonly Epic39ReportingService $reports,
    ) {
    }

    public function storeCreditLiability(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.store_credit.financial.view'), 403);

        return response()->json(
            $this->reports->storeCreditLiability($request->user(), $this->filters($request))
        );
    }

    public function storeCreditMovements(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.store_credit.view'), 403);

        return response()->json(
            $this->reports->storeCreditMovements($request->user(), $this->filters($request))
        );
    }

    public function storeCreditReconciliation(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.epic39_reconciliation.view'), 403);

        return response()->json(
            $this->reports->storeCreditReconciliation($request->user(), $this->filters($request))
        );
    }

    public function loyaltyActivity(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.loyalty.view'), 403);

        return response()->json(
            $this->reports->loyaltyActivity($request->user(), $this->filters($request))
        );
    }

    public function reconciliationExceptions(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.epic39_reconciliation.view'), 403);

        return response()->json(
            $this->reports->reconciliationExceptions($request->user(), $this->filters($request))
        );
    }

    public function customerStatement(Request $request, CustomerFinancialAccount $account): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.customer_accounts.view'), 403);

        return response()->json(
            $this->reports->customerStatement($request->user(), $account, $this->filters($request))
        );
    }

    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'business_date_from' => ['nullable', 'date'],
            'business_date_to' => ['nullable', 'date', 'after_or_equal:business_date_from'],
            'branch_id' => ['nullable', 'uuid'],
            'customer_financial_account_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'entry_type' => ['nullable', 'string'],
            'ledger_category' => ['nullable', 'string'],
            'direction' => ['nullable', 'string', Rule::in(['credit', 'debit'])],
            'source_type' => ['nullable', 'string', 'max:100'],
            'accounting_status' => ['nullable', 'string', Rule::in(['pending', 'processing', 'failed', 'synced'])],
            'points_movement_type' => ['nullable', 'string', 'max:100'],
            'rule_id' => ['nullable', 'uuid'],
            'rule_version' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', Rule::in(['ledger_sequence_desc', 'business_date_desc'])],
        ]);

        if (!empty($filters['business_date_from']) && !empty($filters['business_date_to'])) {
            $from = Carbon::parse($filters['business_date_from']);
            $to = Carbon::parse($filters['business_date_to']);

            if ($from->diffInDays($to) > 366) {
                throw ValidationException::withMessages([
                    'business_date_to' => ['The report date range may not exceed 366 days.'],
                ]);
            }
        }

        return $filters;
    }
}
