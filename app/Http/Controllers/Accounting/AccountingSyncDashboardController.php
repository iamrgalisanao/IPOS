<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\Accounting\AccountingOutboxQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingSyncDashboardController extends Controller
{
    public function __construct(
        protected AccountingOutboxQueryService $queryService,
        protected AccountingOutboxProcessorService $processor
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeView($request);

        $filters = $this->filters($request);
        $records = $this->queryService
            ->query($filters, $request->user())
            ->paginate(25)
            ->withQueryString();

        $records->through(fn (AccountingOutbox $record) => $this->summary($record));

        return Inertia::render('Accounting/Outbox/Index', [
            'filters' => $filters,
            'records' => $records,
            'branches' => $this->availableBranches($request),
            'eventTypes' => ['sale_paid', 'sale_voided', 'sale_refunded'],
            'syncStatuses' => ['pending', 'processing', 'failed', 'synced'],
            'sourceTypes' => ['sale', 'sale_void', 'sale_refund'],
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $this->authorizeView($request);

        $record = $this->queryService->find($id, $request->user());

        abort_if(!$record, 404);

        return Inertia::render('Accounting/Outbox/Show', [
            'record' => $this->detail($record->load(['attempts' => fn ($query) => $query->latest('started_at')]), true),
            'canRetry' => $request->user()->hasPermission('retry_failed_sync') && $record->sync_status === 'failed',
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function retry(Request $request, string $id): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('retry_failed_sync'), 403, 'Unauthorized. Permission required: retry_failed_sync');

        $record = $this->queryService->find($id, $request->user());

        abort_if(!$record, 404);

        if ($record->sync_status !== 'failed') {
            abort(422, 'Only failed accounting outbox records can be retried.');
        }

        $this->processor->process($record);

        $record->refresh();

        return redirect()
            ->route('accounting.outbox.show', $record->id)
            ->with($record->sync_status === 'synced' ? 'status' : 'error', $record->sync_status === 'synced'
                ? 'Retry completed successfully.'
                : 'Retry completed with a failure state.');
    }

    protected function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('view_sync_dashboard'), 403, 'Unauthorized. Permission required: view_sync_dashboard');
    }

    protected function filters(Request $request): array
    {
        return $request->only([
            'event_type',
            'sync_status',
            'source_type',
            'branch_id',
            'date_from',
            'date_to',
        ]);
    }

    protected function availableBranches(Request $request): array
    {
        $query = Branch::query()->where('status', 'active')->orderBy('name');

        if (!$request->user()->hasPermission('view_multi_branch_dashboard')) {
            $query->whereIn('id', $request->user()->branches()->pluck('branches.id'));
        }

        return $query->get(['id', 'name'])->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
        ])->all();
    }

    protected function summary(AccountingOutbox $record): array
    {
        return $this->queryService->serializeSummary($record);
    }

    protected function detail(AccountingOutbox $record, bool $includeAttempts = false): array
    {
        return $this->queryService->serializeDetail($record, $includeAttempts);
    }
}