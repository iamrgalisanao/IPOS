<?php

use App\Services\Support\SupportPayloadMasker;
use App\Services\Support\SupportAuditLogger;
use App\Models\SupportAuditEvent;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'tenant'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    // Accounting integration routes
    Route::prefix('accounting/quickbooks')->name('accounting.quickbooks.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'index'])
            ->middleware('permission:manage_quickbooks_connection')
            ->name('index');
        Route::post('/connect', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'connect'])
            ->middleware('permission:manage_quickbooks_connection')
            ->name('connect');
        Route::get('/callback', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'callback'])
            ->middleware('permission:manage_quickbooks_connection')
            ->name('callback');
        Route::post('/disconnect', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'disconnect'])
            ->middleware('permission:manage_quickbooks_connection')
            ->name('disconnect');
    });

    Route::prefix('accounting/outbox')->name('accounting.outbox.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Accounting\AccountingSyncDashboardController::class, 'index'])
            ->middleware('permission:view_sync_dashboard')
            ->name('index');
        Route::get('/{id}', [\App\Http\Controllers\Accounting\AccountingSyncDashboardController::class, 'show'])
            ->middleware('permission:view_sync_dashboard')
            ->name('show');
        Route::post('/{id}/retry', [\App\Http\Controllers\Accounting\AccountingSyncDashboardController::class, 'retry'])
            ->middleware('permission:retry_failed_sync')
            ->name('retry');
    });

    Route::prefix('accounting/mappings')->name('accounting.mappings.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Accounting\AccountingMappingController::class, 'index'])
            ->middleware('permission:manage_accounting_mappings')
            ->name('index');
        Route::post('/', [\App\Http\Controllers\Accounting\AccountingMappingController::class, 'store'])
            ->middleware('permission:manage_accounting_mappings')
            ->name('store');
        Route::get('/{mapping}', [\App\Http\Controllers\Accounting\AccountingMappingController::class, 'show'])
            ->middleware('permission:manage_accounting_mappings')
            ->name('show');
        Route::put('/{mapping}', [\App\Http\Controllers\Accounting\AccountingMappingController::class, 'update'])
            ->middleware('permission:manage_accounting_mappings')
            ->name('update');
        Route::patch('/{mapping}/status', [\App\Http\Controllers\Accounting\AccountingMappingController::class, 'status'])
            ->middleware('permission:manage_accounting_mappings')
            ->name('status');
    });

    Route::prefix('settlement/periods')->name('settlement.periods.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Settlement\SettlementReviewController::class, 'index'])
            ->middleware('permission:view_settlement_periods')
            ->name('index');
        Route::get('/{period}', [\App\Http\Controllers\Settlement\SettlementReviewController::class, 'show'])
            ->middleware('permission:view_settlement_periods')
            ->name('show');
        Route::post('/{period}/approve', [\App\Http\Controllers\Settlement\SettlementReviewController::class, 'approve'])
            ->middleware('permission:manage_settlement_periods')
            ->name('approve');
        Route::post('/{period}/lock', [\App\Http\Controllers\Settlement\SettlementReviewController::class, 'lock'])
            ->middleware('permission:manage_settlement_periods')
            ->name('lock');
        Route::post('/{period}/reopen', [\App\Http\Controllers\Settlement\SettlementReviewController::class, 'reopen'])
            ->middleware('permission:manage_settlement_periods')
            ->name('reopen');

        // Exports
        Route::get('/{period}/export/summary/csv', [\App\Http\Controllers\Settlement\SettlementExportController::class, 'summaryCsv'])
            ->middleware('permission:export_reports')
            ->name('export.summary.csv');
        Route::get('/{period}/export/summary/pdf', [\App\Http\Controllers\Settlement\SettlementExportController::class, 'summaryPdf'])
            ->middleware('permission:export_reports')
            ->name('export.summary.pdf');
        Route::get('/{period}/export/variance/csv', [\App\Http\Controllers\Settlement\SettlementExportController::class, 'varianceCsv'])
            ->middleware('permission:export_accounting_reports')
            ->name('export.variance.csv');
        Route::get('/{period}/export/sync-status/csv', [\App\Http\Controllers\Settlement\SettlementExportController::class, 'syncStatusCsv'])
            ->middleware('permission:export_accounting_reports')
            ->name('export.sync-status.csv');
    });

    Route::prefix('reports/tax')->name('reports.tax.')->group(function () {
        Route::get('/', [\App\Http\Controllers\TaxReportingController::class, 'index'])
            ->middleware('permission:view_reports')
            ->name('index');
        Route::get('/export/csv', [\App\Http\Controllers\TaxReportingController::class, 'exportCsv'])
            ->middleware('permission:view_reports')
            ->name('export.csv');
    });

    Route::prefix('sales/history')->name('sales.history.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Sales\SalesHistoryController::class, 'index'])
            ->middleware('permission:view_sales_history')
            ->name('index');
        Route::get('/export', [\App\Http\Controllers\Sales\SalesHistoryController::class, 'export'])
            ->middleware('permission:export_sales_history')
            ->name('export');
        Route::get('/{sale}', [\App\Http\Controllers\Sales\SalesHistoryController::class, 'show'])
            ->middleware('permission:view_sale_details')
            ->name('show');
    });

    // Shift Management
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Shift\ShiftSummaryController::class, 'index'])
            ->middleware('permission:view_shift')
            ->name('index');

        // Operational Actions (Require Branch Context)
        Route::middleware(['branch'])->group(function () {
            Route::get('/open', [\App\Http\Controllers\Shift\ShiftController::class, 'open'])
                ->middleware('permission:open_shift')
                ->name('open');
            Route::post('/', [\App\Http\Controllers\Shift\ShiftController::class, 'store'])
                ->middleware('permission:open_shift')
                ->name('store');
            Route::post('/{shift}/submit-closing', [\App\Http\Controllers\Shift\ShiftController::class, 'submitClosing'])
                ->middleware('permission:close_shift')
                ->name('submit-closing');
            Route::post('/{shift}/approve', [\App\Http\Controllers\Shift\ShiftController::class, 'approve'])
                ->middleware('permission:approve_shift')
                ->name('approve');
            Route::post('/drawer-events', [\App\Http\Controllers\Shift\ShiftController::class, 'recordDrawerEvent'])
                ->middleware('permission:manage_cash_drawer')
                ->name('drawer-events');
        });

        Route::get('/{shift}', [\App\Http\Controllers\Shift\ShiftSummaryController::class, 'show'])
            ->middleware('permission:view_shift')
            ->name('show');
            
        Route::get('/{shift}/z-report', [\App\Http\Controllers\Shift\ShiftSummaryController::class, 'zReport'])
            ->middleware('permission:view_shift')
            ->name('z-report');
    });

    // POS Routes
    Route::middleware(['branch'])->group(function () {
        Route::get('/pos', [\App\Http\Controllers\POSController::class, 'index'])->name('pos.index');
        Route::get('/pos/search', [\App\Http\Controllers\POSController::class, 'search'])->name('pos.search');
        Route::get('/pos/active-shift', [\App\Http\Controllers\POSController::class, 'activeShift'])->name('pos.active-shift');
        Route::get('/pos/layout', [\App\Http\Controllers\POSController::class, 'layout'])->name('pos.layout');
    });

    // Checkout Validation: requires branch context + create_sale permission
    Route::middleware(['branch', 'permission:create_sale'])->group(function () {
        Route::post('/pos/checkout/validate', [\App\Http\Controllers\CheckoutController::class, 'validateDraft'])
             ->name('pos.checkout.validate');

        Route::post('/pos/checkout/create-sale', [\App\Http\Controllers\CheckoutController::class, 'createSale'])
             ->name('pos.checkout.create-sale');

           Route::post('/pos/checkout/status', [\App\Http\Controllers\CheckoutController::class, 'checkStatus'])
               ->name('pos.checkout.status');

        Route::get('/pos/sales/{sale_id}/receipt', [\App\Http\Controllers\ReceiptController::class, 'show'])
             ->name('pos.sales.receipt');

        Route::post('/pos/sales/{sale_id}/payments', [\App\Http\Controllers\POS\PaymentController::class, 'store'])
             ->name('pos.sales.payments');

        Route::post('/pos/sales/{sale_id}/payments/split', [\App\Http\Controllers\POS\PaymentController::class, 'storeSplit'])
             ->name('pos.sales.payments.split');
    });

    // Inventory Routes
    Route::middleware(['branch', 'permission:view_branch_inventory'])->group(function () {
        Route::get('/inventory/movements', [\App\Http\Controllers\Inventory\InventoryMovementController::class, 'index'])
             ->name('inventory.movements.index');
    });

    // POS Layout Admin Routes
    Route::prefix('admin/pos-layouts')->name('admin.pos-layouts.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PosLayoutController::class, 'index'])
            ->middleware('permission:pos-layouts.view')
            ->name('index');
        Route::post('/', [\App\Http\Controllers\Admin\PosLayoutController::class, 'store'])
            ->middleware('permission:pos-layouts.manage')
            ->name('store');
        Route::get('/{posLayout}', [\App\Http\Controllers\Admin\PosLayoutController::class, 'show'])
            ->middleware('permission:pos-layouts.view')
            ->name('show');
        Route::put('/{posLayout}', [\App\Http\Controllers\Admin\PosLayoutController::class, 'update'])
            ->middleware('permission:pos-layouts.manage')
            ->name('update');
        Route::patch('/{posLayout}', [\App\Http\Controllers\Admin\PosLayoutController::class, 'update'])
            ->middleware('permission:pos-layouts.manage');
        Route::post('/{posLayout}/archive', [\App\Http\Controllers\Admin\PosLayoutController::class, 'archive'])
            ->middleware('permission:pos-layouts.manage')
            ->name('archive');
        Route::post('/{posLayout}/publish', [\App\Http\Controllers\Admin\PosLayoutController::class, 'publish'])
            ->middleware('permission:pos-layouts.manage')
            ->name('publish');
    });
});

Route::prefix('/support/assisted/{supportAccessSession}')
    ->middleware(['auth', 'support.assisted'])
    ->name('support.assisted.')
    ->group(function () {
        Route::match(['get', 'post', 'put', 'patch', 'delete'], '/probe', function (\App\Models\SupportAccessSession $supportAccessSession) {
            $maskedPayload = app(SupportPayloadMasker::class)->mask([
                'connection_status' => 'connected',
                'realm_id' => '123456789',
                'access_token' => 'abc',
                'refresh_token' => 'def',
                'headers' => [
                    'Authorization' => 'Bearer xyz',
                ],
                'metadata' => [
                    'client_secret' => 'secret-value',
                    'provider_payload' => [
                        'status' => 'raw',
                        'token' => 'hidden',
                    ],
                ],
                'gross_total' => 1024.55,
                'support_session_id' => $supportAccessSession->id,
                'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId(),
            ], $supportAccessSession->masking_profile);

            app(SupportAuditLogger::class)->log(
                eventType: 'support.route.accessed',
                supportSession: $supportAccessSession,
                actor: $supportAccessSession->supportUser,
                routeName: request()->route()?->getName(),
                path: request()->path(),
                method: request()->method(),
                metadata: [
                    'masking_profile' => $supportAccessSession->masking_profile,
                    'response' => $maskedPayload,
                ]
            );

            return response()->json([
                'support_session_id' => $supportAccessSession->id,
                'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId(),
                'branch_id' => app(\App\Services\BranchContext::class)->getBranchId(),
                'mode' => 'support_assisted',
                'masking_profile' => $supportAccessSession->masking_profile,
                'masked_payload' => $maskedPayload,
            ]);
        })->name('probe');

        Route::get('/audit-events', function (\App\Models\SupportAccessSession $supportAccessSession) {
            $masker = app(SupportPayloadMasker::class);
            $limit = (int) request()->integer('limit', 25);
            $limit = max(1, min($limit, 100));

            $events = SupportAuditEvent::query()
                ->where('support_session_id', $supportAccessSession->id)
                ->latest('created_at')
                ->limit($limit)
                ->get()
                ->map(function (SupportAuditEvent $event) use ($masker, $supportAccessSession) {
                    return [
                        'id' => $event->id,
                        'event_type' => $event->event_type,
                        'support_session_id' => $event->support_session_id,
                        'route_name' => $event->route_name,
                        'path' => $event->path,
                        'method' => $event->method,
                        'status' => $event->status,
                        'metadata' => $masker->mask($event->metadata ?? [], $supportAccessSession->masking_profile),
                        'created_at' => $event->created_at?->toISOString(),
                    ];
                })
                ->values();

            app(SupportAuditLogger::class)->log(
                eventType: 'support.audit_review.accessed',
                supportSession: $supportAccessSession,
                actor: $supportAccessSession->supportUser,
                routeName: request()->route()?->getName(),
                path: request()->path(),
                method: request()->method(),
                metadata: [
                    'masking_profile' => $supportAccessSession->masking_profile,
                    'limit' => $limit,
                    'returned_count' => $events->count(),
                ]
            );

            return response()->json([
                'data' => $events,
                'meta' => [
                    'limit' => $limit,
                    'count' => $events->count(),
                    'mode' => 'support_assisted',
                    'support_session_id' => $supportAccessSession->id,
                ],
            ]);
        })->name('audit-events.index');
    });

require __DIR__.'/auth.php';
