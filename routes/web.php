<?php

use App\Services\Support\SupportPayloadMasker;
use App\Services\Support\SupportAuditLogger;
use App\Models\SupportAuditEvent;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Inventory\InventoryDashboardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Auth/Login', [
        'canResetPassword' => Route::has('password.request'),
        'status' => session('status'),
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

Route::middleware(['auth', 'platform.admin'])->prefix('system-admin/tenants')->name('system-admin.tenants.')->group(function () {
    Route::get('/', [\App\Http\Controllers\SystemAdmin\TenantProvisioningController::class, 'index'])
        ->name('index');
    Route::post('/', [\App\Http\Controllers\SystemAdmin\TenantProvisioningController::class, 'store'])
        ->name('store');
    Route::put('/{tenant}', [\App\Http\Controllers\SystemAdmin\TenantProvisioningController::class, 'update'])
        ->name('update');
});

Route::middleware(['auth', 'platform.admin'])->prefix('system-admin/tenants')->name('system-admin.onboarding.')->group(function () {
    Route::get('{company}/onboarding', [\App\Http\Controllers\SystemAdmin\CompanyOnboardingController::class, 'show'])
        ->name('show');
    Route::post('{company}/onboarding/create-branch', [\App\Http\Controllers\SystemAdmin\CompanyOnboardingController::class, 'createInitialBranch'])
        ->name('create-branch');
    Route::post('{company}/onboarding/create-owner', [\App\Http\Controllers\SystemAdmin\CompanyOnboardingController::class, 'createOwnerUser'])
        ->name('create-owner');
    Route::post('{company}/onboarding/register-machine-profile', [\App\Http\Controllers\SystemAdmin\CompanyOnboardingController::class, 'registerMachineProfile'])
        ->name('register-machine-profile');
    Route::get('{company}/onboarding/bootstrap-progress', [\App\Http\Controllers\SystemAdmin\CompanyOnboardingController::class, 'getBootstrapProgress'])
        ->name('bootstrap-progress');
    Route::post('{company}/onboarding/resend-bootstrap', [\App\Http\Controllers\SystemAdmin\CompanyOnboardingController::class, 'resendBootstrapLink'])
        ->name('resend-bootstrap');
});

Route::middleware(['auth', 'platform.admin'])->prefix('system-admin/tenants')->name('system-admin.')->group(function () {
    Route::get('{company}/readiness', [\App\Http\Controllers\SystemAdmin\TenantReadinessController::class, 'show'])
        ->name('readiness.show');
    Route::get('{company}/readiness/export', [\App\Http\Controllers\SystemAdmin\TenantReadinessController::class, 'export'])
        ->name('readiness.export');
    Route::post('{company}/sign-off-readiness', [\App\Http\Controllers\SystemAdmin\TenantReadinessController::class, 'signOff'])
        ->name('readiness.sign-off');
    Route::get('{company}/pilot-eligibility', [\App\Http\Controllers\SystemAdmin\PilotProvisioningController::class, 'eligibility'])
        ->name('pilot.eligibility');
    Route::post('{company}/pilot-enable', [\App\Http\Controllers\SystemAdmin\PilotProvisioningController::class, 'enable'])
        ->name('pilot.enable');
    Route::post('{company}/pilot-disable', [\App\Http\Controllers\SystemAdmin\PilotProvisioningController::class, 'disable'])
        ->name('pilot.disable');
});

Route::middleware(['auth', 'platform.admin'])->prefix('system-admin/dashboard')->name('system-admin.dashboard.')->group(function () {
    Route::get('/', [\App\Http\Controllers\SystemAdmin\SystemAdminDashboardController::class, 'index'])
        ->name('index');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    // Accounting Integration Premium Feature Gate
    Route::middleware(['subscription.feature:quickbooks.sync'])->group(function () {
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

    Route::middleware(['subscription.feature:reports.basic'])->group(function () {
        Route::prefix('reports/tax')->name('reports.tax.')->group(function () {
            Route::get('/', [\App\Http\Controllers\TaxReportingController::class, 'index'])
                ->middleware('permission:view_reports')
                ->name('index');
            Route::get('/export/csv', [\App\Http\Controllers\TaxReportingController::class, 'exportCsv'])
                ->middleware('permission:view_reports')
                ->name('export.csv');
            Route::get('/export/ejournal', [\App\Http\Controllers\TaxReportingController::class, 'exportEJournal'])
                ->middleware('permission:view_reports')
                ->name('export.ejournal');
        });
    });

    Route::middleware(['subscription.feature:reports.advanced'])->group(function () {
        Route::prefix('reports/cashier-accountability')->name('reports.cashier-accountability.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Reports\CashierAccountabilityController::class, 'index'])
                ->name('index');
            Route::get('/{shift}', [\App\Http\Controllers\Reports\CashierAccountabilityController::class, 'show'])
                ->name('show');
            Route::get('/{shift}/export', [\App\Http\Controllers\Reports\CashierAccountabilityController::class, 'export'])
                ->name('export');
        });
    });

    Route::prefix('reports/sales-summary')->name('reports.sales-summary.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Reports\SalesSummaryReportController::class, 'index'])
            ->middleware('permission:view_sales_history')
            ->name('index');
        Route::get('/export', [\App\Http\Controllers\Reports\SalesSummaryReportController::class, 'export'])
            ->middleware('permission:export_sales_history')
            ->name('export');
    });

    Route::prefix('reports/product-mix')->name('reports.product-mix.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Reports\ProductMixReportController::class, 'index'])
            ->middleware('permission:view_sales_history')
            ->name('index');
        Route::get('/export', [\App\Http\Controllers\Reports\ProductMixReportController::class, 'export'])
            ->middleware('permission:export_sales_history')
            ->name('export');
    });

    Route::prefix('reports/sales-timing')->name('reports.sales-timing.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Reports\SalesTimingReportController::class, 'index'])
            ->middleware('permission:view_sales_history')
            ->name('index');
        Route::get('/export', [\App\Http\Controllers\Reports\SalesTimingReportController::class, 'export'])
            ->middleware('permission:export_sales_history')
            ->name('export');
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

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        // Data Exports
        Route::get('/exports', [\App\Http\Controllers\Reports\DataExportController::class, 'index'])->name('exports.index');
        Route::get('/exports/{export}/download', [\App\Http\Controllers\Reports\DataExportController::class, 'download'])->name('exports.download');
    });

    // Shift Management
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Shift\ShiftSummaryController::class, 'index'])
            ->middleware('permission:view_shift')
            ->name('index');

        // Operational Actions (Require Branch Context)
        Route::middleware(['branch'])->group(function () {
            Route::get('/open', [\App\Http\Controllers\Shift\ShiftController::class, 'open'])
                ->middleware(['permission:open_shift', 'timecard.clocked_in'])
                ->name('open');
            Route::post('/', [\App\Http\Controllers\Shift\ShiftController::class, 'store'])
                ->middleware(['permission:open_shift', 'timecard.clocked_in'])
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
            Route::post('/{shift}/spot-audit', [\App\Http\Controllers\Shift\SpotAuditController::class, 'store'])
                ->name('spot-audit');
        });

        Route::get('/{shift}', [\App\Http\Controllers\Shift\ShiftSummaryController::class, 'show'])
            ->middleware('permission:view_shift')
            ->name('show');

        Route::get('/{shift}/z-report', [\App\Http\Controllers\Shift\ShiftSummaryController::class, 'zReport'])
            ->middleware('permission:view_shift')
            ->name('z-report');
    });

    // Epic 41: POS Terminal Tablet Production Routes
    // Terminal identity binding is enforced via the `terminal` middleware, which
    // resolves a single verified SalesMachineProfile for the active tenant and
    // branch and fails closed when terminal context is missing or invalid.
    // Reference: docs/implementation-plans/epic-41-terminal-identity-binding-planning-lock.md
    Route::prefix('pos/terminal')
        ->name('pos.terminal.')
        ->middleware([
            'auth',
            'tenant',
            'branch',
            'terminal',
            'subscription.feature:sales.pos',
        ])
        ->group(function () {
            Route::get('/checkout', [\App\Http\Controllers\CheckoutController::class, 'index'])->name('checkout');
            Route::get('/floor-map', [\App\Http\Controllers\POS\DiningFloorMapController::class, 'show'])->name('floor-map');
            Route::get('/shift', [\App\Http\Controllers\CheckoutController::class, 'shift'])->name('shift');
            Route::get('/sync-status', [\App\Http\Controllers\CheckoutController::class, 'syncStatus'])->name('sync-status');
            Route::get('/settings', [\App\Http\Controllers\CheckoutController::class, 'settings'])->name('settings');
        });

    // POS Shell Routes (Legacy transition): /pos is no longer a render surface.
    // Production tablet checkout is canonical at /pos/terminal/checkout.
    Route::middleware(['branch', 'subscription.feature:sales.pos'])->group(function () {
        Route::redirect('/pos', '/pos/terminal/checkout')->name('pos.index');
    });

    // POS operational routes require a verified terminal context. These routes
    // are used by the tablet shell after /pos/terminal/checkout has resolved
    // tenant, branch, and terminal identity.
    Route::middleware(['branch', 'terminal', 'subscription.feature:sales.pos'])->group(function () {
        Route::get('/pos/search', [\App\Http\Controllers\POSController::class, 'search'])->name('pos.search');
        Route::get('/pos/active-shift', [\App\Http\Controllers\POSController::class, 'activeShift'])->name('pos.active-shift');
        Route::get('/pos/layout', [\App\Http\Controllers\POSController::class, 'layout'])
            ->middleware('subscription.feature:layout.custom')
            ->name('pos.layout');
        Route::post('/pos/unlock', [\App\Http\Controllers\POSController::class, 'unlock'])->name('pos.unlock');

        Route::post('/pos/sales/{sale}/void', [\App\Http\Controllers\POS\VoidRefundController::class, 'void'])
            ->middleware('idempotent')
            ->name('pos.sales.void');
        Route::post('/pos/sales/{sale}/refund', [\App\Http\Controllers\POS\VoidRefundController::class, 'refund'])
            ->middleware('idempotent')
            ->name('pos.sales.refund');

        // Epic 36: Local Register Sync & Store-Level Coordination
        Route::post('/pos/local-sync/broker/register', [\App\Http\Controllers\POS\LocalSyncController::class, 'registerBroker'])
            ->name('pos.local-sync.broker.register');
        Route::get('/pos/local-sync/broker/discover', [\App\Http\Controllers\POS\LocalSyncController::class, 'discoverBroker'])
            ->name('pos.local-sync.broker.discover');
        Route::post('/pos/local-sync/table/lock', [\App\Http\Controllers\POS\LocalSyncController::class, 'lockTable'])
            ->name('pos.local-sync.table.lock');
        Route::post('/pos/local-sync/table/unlock', [\App\Http\Controllers\POS\LocalSyncController::class, 'unlockTable'])
            ->name('pos.local-sync.table.unlock');

        // Timecard status check (within auth/tenant/branch context)
        Route::get('/pos/timecard/status', [\App\Http\Controllers\POS\TimecardController::class, 'status'])
            ->name('pos.timecard.status');

        Route::get('/pos/dining/floor-map', [\App\Http\Controllers\POS\DiningFloorMapController::class, 'index'])
            ->middleware('permission:create_sale')
            ->name('pos.dining.floor-map.index');

        Route::post('/pos/offline-sync', [\App\Http\Controllers\POS\OfflineSyncController::class, 'sync'])
            ->middleware('permission:create_sale')
            ->name('pos.offline-sync.web');
    });

    // Checkout Validation: requires branch context + create_sale permission + sales.pos entitlement + terminal + timecard.clocked_in
    Route::middleware(['branch', 'permission:create_sale', 'subscription.feature:sales.pos', 'terminal', 'timecard.clocked_in'])->group(function () {
        Route::post('/pos/dining/tickets', [\App\Http\Controllers\POS\DiningTicketController::class, 'store'])
            ->name('pos.dining.tickets.store');

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

    // Epic 20: Procurement & Suppliers
    Route::middleware(['subscription.feature:procurement.basic'])->group(function () {
        Route::prefix('procurement/suppliers')->name('procurement.suppliers.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Procurement\SupplierController::class, 'index'])
                ->middleware('permission:procurement.suppliers.view')
                ->name('index');
            Route::get('/create', [\App\Http\Controllers\Procurement\SupplierController::class, 'create'])
                ->middleware('permission:procurement.suppliers.manage')
                ->name('create');
            Route::post('/', [\App\Http\Controllers\Procurement\SupplierController::class, 'store'])
                ->middleware('permission:procurement.suppliers.manage')
                ->name('store');
            Route::get('/{supplier}', [\App\Http\Controllers\Procurement\SupplierController::class, 'show'])
                ->middleware('permission:procurement.suppliers.view')
                ->name('show');
            Route::get('/{supplier}/edit', [\App\Http\Controllers\Procurement\SupplierController::class, 'edit'])
                ->middleware('permission:procurement.suppliers.manage')
                ->name('edit');
            Route::put('/{supplier}', [\App\Http\Controllers\Procurement\SupplierController::class, 'update'])
                ->middleware('permission:procurement.suppliers.manage')
                ->name('update');
            Route::patch('/{supplier}/toggle-status', [\App\Http\Controllers\Procurement\SupplierController::class, 'toggleStatus'])
                ->middleware('permission:procurement.suppliers.manage')
                ->name('toggle-status');
        });
    });

    // Epic 20: Purchase Orders
    Route::middleware(['subscription.feature:procurement.basic'])->group(function () {
        Route::prefix('procurement/purchase-orders')->name('procurement.purchase-orders.')->group(function () {
            Route::get('/export', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'export'])
                ->middleware('permission:procurement.purchase-orders.export')
                ->name('export');
            Route::get('/{purchaseOrder}/export', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'exportOne'])
                ->middleware('permission:procurement.purchase-orders.export')
                ->name('export-one');

            Route::get('/', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'index'])
                ->middleware('permission:procurement.purchase-orders.view')
                ->name('index');
            Route::get('/create', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'create'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('create');
            Route::post('/', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'store'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('store');
            Route::get('/{purchaseOrder}', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'show'])
                ->middleware('permission:procurement.purchase-orders.view')
                ->name('show');
            Route::get('/{purchaseOrder}/edit', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'edit'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('edit');
            Route::put('/{purchaseOrder}', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'update'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('update');

            // Lifecycle transitions
            Route::post('/{purchaseOrder}/submit', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'submit'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('submit');
            Route::post('/{purchaseOrder}/approve', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'approve'])
                ->middleware('permission:procurement.purchase-orders.approve')
                ->name('approve');
            Route::post('/{purchaseOrder}/send', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'send'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('send');
            Route::post('/{purchaseOrder}/complete', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'complete'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('complete');
            Route::post('/{purchaseOrder}/cancel', [\App\Http\Controllers\Procurement\PurchaseOrderController::class, 'cancel'])
                ->middleware('permission:procurement.purchase-orders.create')
                ->name('cancel');
        });
    });

    // Epic 20: Purchase Receivings
    Route::middleware(['subscription.feature:procurement.basic'])->group(function () {
        Route::prefix('procurement/receivings')->name('procurement.receivings.')->group(function () {
            Route::get('/export', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'export'])
                ->middleware('permission:procurement.receiving.export')
                ->name('export');
            Route::get('/{purchaseReceiving}/export', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'exportOne'])
                ->middleware('permission:procurement.receiving.export')
                ->name('export-one');

            Route::get('/', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'index'])
                ->middleware('permission:procurement.receiving.view')
                ->name('index');
            Route::get('/create', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'create'])
                ->middleware('permission:procurement.receiving.create')
                ->name('create');
            Route::post('/', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'store'])
                ->middleware('permission:procurement.receiving.create')
                ->name('store');
            Route::get('/{purchaseReceiving}', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'show'])
                ->middleware('permission:procurement.receiving.view')
                ->name('show');
            Route::get('/{purchaseReceiving}/edit', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'edit'])
                ->middleware('permission:procurement.receiving.create')
                ->name('edit');
            Route::put('/{purchaseReceiving}', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'update'])
                ->middleware('permission:procurement.receiving.create')
                ->name('update');
            Route::post('/{purchaseReceiving}/cancel', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'cancel'])
                ->middleware('permission:procurement.receiving.create')
                ->name('cancel');
            Route::post('/{purchaseReceiving}/post', [\App\Http\Controllers\Procurement\PurchaseReceivingController::class, 'post'])
                ->middleware('permission:procurement.receiving.post')
                ->name('post');
        });
    });

    // Epic 26: Supplier Returns / RMA
    Route::middleware(['subscription.feature:procurement.advanced'])->group(function () {
        Route::prefix('procurement/returns')->name('procurement.returns.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'index'])
                ->middleware('permission:procurement.returns.view')
                ->name('index');
            Route::get('/create', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'create'])
                ->middleware('permission:procurement.returns.create')
                ->name('create');
            Route::post('/', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'store'])
                ->middleware('permission:procurement.returns.create')
                ->name('store');
            Route::get('/{supplierReturn}', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'show'])
                ->middleware('permission:procurement.returns.view')
                ->name('show');
            Route::get('/{supplierReturn}/edit', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'edit'])
                ->middleware('permission:procurement.returns.create')
                ->name('edit');
            Route::put('/{supplierReturn}', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'update'])
                ->middleware('permission:procurement.returns.create')
                ->name('update');
            Route::post('/{supplierReturn}/submit', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'submit'])
                ->middleware('permission:procurement.returns.create')
                ->name('submit');
            Route::post('/{supplierReturn}/approve', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'approve'])
                ->middleware('permission:procurement.returns.approve')
                ->name('approve');
            Route::post('/{supplierReturn}/cancel', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'cancel'])
                ->middleware('permission:procurement.returns.create')
                ->name('cancel');
            Route::post('/{supplierReturn}/post', [\App\Http\Controllers\Procurement\SupplierReturnController::class, 'post'])
                ->middleware('permission:procurement.returns.post')
                ->name('post');
        });
    });

    // Inventory Routes
    Route::middleware(['branch'])->group(function () {
        Route::get('/inventory/hub', [\App\Http\Controllers\Inventory\InventoryHubController::class, 'index'])
             ->middleware('permission:view_branch_inventory|inventory.stocktake.view|view_inventory_reports|audit_inventory|manage_products|manage_unit_conversions|procurement.suppliers.view|procurement.purchase-orders.view|procurement.receiving.view|procurement.returns.view')
             ->name('inventory.hub.index');

        Route::get('/inventory/dashboard', [InventoryDashboardController::class, 'index'])
             ->middleware('permission:view_branch_inventory|inventory.stocktake.view')
             ->name('inventory.dashboard.index');

        Route::get('/inventory/movements', [\App\Http\Controllers\Inventory\InventoryMovementController::class, 'index'])
             ->middleware('permission:view_branch_inventory')
             ->name('inventory.movements.index');

        Route::prefix('inventory/stocktakes')->name('inventory.stocktakes.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Inventory\StocktakeController::class, 'index'])
                 ->middleware('permission:inventory.stocktake.view')
                 ->name('index');

            Route::get('/create', [\App\Http\Controllers\Inventory\StocktakeController::class, 'create'])
                 ->middleware('permission:inventory.stocktake.create')
                 ->name('create');

            Route::post('/', [\App\Http\Controllers\Inventory\StocktakeController::class, 'store'])
                 ->middleware('permission:inventory.stocktake.create')
                 ->name('store');

            Route::get('/catalog/search', [\App\Http\Controllers\Inventory\StocktakeController::class, 'searchCatalog'])
                 ->middleware('permission:inventory.stocktake.count')
                 ->name('catalog.search');

            Route::get('/{stocktakeSession}', [\App\Http\Controllers\Inventory\StocktakeController::class, 'show'])
                 ->middleware('permission:inventory.stocktake.view')
                 ->name('show');

            Route::post('/{stocktakeSession}/start-counting', [\App\Http\Controllers\Inventory\StocktakeController::class, 'startCounting'])
                 ->middleware('permission:inventory.stocktake.count')
                 ->name('start-counting');

            Route::post('/{stocktakeSession}/cancel', [\App\Http\Controllers\Inventory\StocktakeController::class, 'cancel'])
                 ->middleware('permission:inventory.stocktake.cancel')
                 ->name('cancel');

            Route::put('/{stocktakeSession}/lines', [\App\Http\Controllers\Inventory\StocktakeController::class, 'updateLines'])
                 ->middleware('permission:inventory.stocktake.count')
                 ->name('lines.update');

            Route::post('/{stocktakeSession}/submit', [\App\Http\Controllers\Inventory\StocktakeController::class, 'submitForReview'])
                 ->middleware('permission:inventory.stocktake.count')
                 ->name('submit');

            Route::put('/{stocktakeSession}/reasons', [\App\Http\Controllers\Inventory\StocktakeController::class, 'updateVarianceReasons'])
                 ->middleware('permission:inventory.stocktake.review')
                 ->name('variance-reasons.update');

            Route::post('/{stocktakeSession}/reject', [\App\Http\Controllers\Inventory\StocktakeController::class, 'reject'])
                 ->middleware('permission:inventory.stocktake.review')
                 ->name('reject');

            Route::post('/{stocktakeSession}/post', [\App\Http\Controllers\Inventory\StocktakeController::class, 'post'])
                 ->middleware('permission:inventory.stocktake.post')
                 ->name('post');


            Route::get('/{stocktakeSession}/summary', [\App\Http\Controllers\Inventory\StocktakeReportController::class, 'summary'])
                 ->middleware('permission:inventory.stocktake.view')
                 ->name('summary');

            Route::get('/{stocktakeSession}/export/variance-csv', [\App\Http\Controllers\Inventory\StocktakeReportController::class, 'exportVarianceCsv'])
                 ->middleware('permission:inventory.stocktake.view')
                 ->name('export.variance-csv');

            Route::post('/{stocktakeSession}/add-line', [\App\Http\Controllers\Inventory\StocktakeController::class, 'addLine'])
                 ->middleware('permission:inventory.stocktake.count')
                 ->name('add-line');
        });
    });

    // POS Layout Admin Premium Feature Gate
    Route::middleware(['subscription.feature:layout.custom'])->group(function () {
        // POS Layout Admin Routes
        Route::prefix('admin/pos-layouts')->name('admin.pos-layouts.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PosLayoutController::class, 'index'])
                ->middleware('permission:pos-layouts.view')
                ->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\PosLayoutController::class, 'create'])
                ->middleware('permission:pos-layouts.manage')
                ->name('create');
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
                ->middleware('permission:pos-layouts.publish')
                ->name('publish');
            Route::post('/{posLayout}/rollback', [\App\Http\Controllers\Admin\PosLayoutController::class, 'rollback'])
                ->middleware('permission:pos-layouts.publish')
                ->name('rollback');
        });

        Route::prefix('admin/service-areas')->name('admin.service-areas.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'index'])
                ->middleware('permission:pos-layouts.manage')
                ->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'store'])
                ->middleware('permission:pos-layouts.manage')
                ->name('store');
            Route::get('/{serviceArea}', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'show'])
                ->middleware('permission:pos-layouts.manage')
                ->name('show');
            Route::put('/{serviceArea}', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'update'])
                ->middleware('permission:pos-layouts.manage')
                ->name('update');
            Route::delete('/{serviceArea}', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'destroy'])
                ->middleware('permission:pos-layouts.manage')
                ->name('destroy');
            Route::patch('/{serviceArea}/activation', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'activation'])
                ->middleware('permission:pos-layouts.manage')
                ->name('activation');
            Route::put('/{serviceArea}/layout', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'layout'])
                ->middleware('permission:pos-layouts.manage')
                ->name('layout.update');

            Route::post('/{serviceArea}/tables', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'storeTable'])
                ->middleware('permission:pos-layouts.manage')
                ->name('tables.store');
            Route::put('/{serviceArea}/tables/{diningTable}', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'updateTable'])
                ->middleware('permission:pos-layouts.manage')
                ->name('tables.update');
            Route::delete('/{serviceArea}/tables/{diningTable}', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'destroyTable'])
                ->middleware('permission:pos-layouts.manage')
                ->name('tables.destroy');
            Route::patch('/{serviceArea}/tables/{diningTable}/activation', [\App\Http\Controllers\Admin\ServiceAreaController::class, 'tableActivation'])
                ->middleware('permission:pos-layouts.manage')
                ->name('tables.activation');
        });

        // Terminal Layout Assignment (per-register override)
        Route::prefix('admin/sales-machine-profiles')
            ->name('admin.sales-machine-profiles.')
            ->group(function () {
                Route::put(
                    '/{profile}/layout-assignment',
                    [\App\Http\Controllers\Admin\LayoutAssignmentController::class, 'update']
                )
                    ->middleware('permission:pos-layouts.manage')
                    ->name('layout-assignment.update');

                Route::delete(
                    '/{profile}/layout-assignment',
                    [\App\Http\Controllers\Admin\LayoutAssignmentController::class, 'destroy']
                )
                    ->middleware('permission:pos-layouts.manage')
                    ->name('layout-assignment.destroy');
            });
    });


    // Tenant User Management
    Route::prefix('admin/users')->name('admin.users.')->middleware('permission:manage_users')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\UserController::class, 'index'])
            ->name('index');
        Route::post('/', [\App\Http\Controllers\Admin\UserController::class, 'store'])
            ->name('store');
        Route::put('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])
            ->name('update');
    });

    // Product Catalog Admin Routes
    Route::prefix('admin/product-categories')->name('admin.product-categories.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'index'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.view'])
            ->name('index');
        Route::get('/export/csv', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'export'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.view'])
            ->name('export');
        Route::get('/import/template/csv', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'importTemplate'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('import.template');
        Route::post('/import/preview', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'previewImport'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('import.preview');
        Route::post('/', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'store'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('store');
        Route::put('/{productCategory}', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'update'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('update');
        Route::delete('/{productCategory}', [\App\Http\Controllers\Admin\ProductCategoryController::class, 'destroy'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('destroy');
    });

    Route::prefix('admin/products')->name('admin.products.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\ProductController::class, 'index'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.view'])
            ->name('index');
        Route::get('/export/csv', [\App\Http\Controllers\Admin\ProductController::class, 'export'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.view'])
            ->name('export');
        Route::get('/import/template/csv', [\App\Http\Controllers\Admin\ProductController::class, 'importTemplate'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('import.template');
        Route::post('/import/preview', [\App\Http\Controllers\Admin\ProductController::class, 'previewImport'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('import.preview');
        Route::get('/create', [\App\Http\Controllers\Admin\ProductController::class, 'create'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\ProductController::class, 'store'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('store');
        Route::get('/{product}/edit', [\App\Http\Controllers\Admin\ProductController::class, 'edit'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('edit');
        Route::put('/{product}', [\App\Http\Controllers\Admin\ProductController::class, 'update'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('update');
        Route::delete('/{product}', [\App\Http\Controllers\Admin\ProductController::class, 'destroy'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('destroy');

        // Branch-specific pricing
        Route::post('/{product}/branch-pricing', [\App\Http\Controllers\Admin\ProductController::class, 'updateBranchPricing'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('branch-pricing.update');
        Route::delete('/{product}/branch-pricing/{branchPricing}', [\App\Http\Controllers\Admin\ProductController::class, 'destroyBranchPricing'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('branch-pricing.destroy');

        // Recipe Management
        Route::post('/{product}/recipe', [\App\Http\Controllers\Admin\ProductController::class, 'updateRecipe'])
            ->middleware(['permission:manage_products', 'subscription.feature:catalog.edit'])
            ->name('recipe.update');

        // Recipe Costing (Story 35.4) — WAC-based estimated cost per composite product unit
        Route::get('/{product}/recipe-cost', [\App\Http\Controllers\Admin\ProductController::class, 'recipeCost'])
            ->middleware(['permission:manage_products'])
            ->name('recipe.cost');
    });

    // Branch settings and deduction policy
    Route::middleware(['permission:edit_branch_policy'])->group(function () {
        Route::get('/admin/branches', [\App\Http\Controllers\Admin\BranchPolicyController::class, 'index'])
            ->name('admin.branches.index');
        Route::put('/admin/branches/{branch}/inventory-policy', [\App\Http\Controllers\Admin\BranchPolicyController::class, 'update'])
            ->name('admin.branches.inventory-policy.update');
    });

    // Branch Payment Settings overrides
    Route::middleware(['permission:manage_payment_methods'])->group(function () {
        Route::get('/admin/branches/{branch}/payment-settings', [\App\Http\Controllers\Admin\BranchPaymentSettingsController::class, 'edit'])
            ->name('admin.branches.payment-settings.edit');
        Route::post('/admin/branches/{branch}/payment-settings', [\App\Http\Controllers\Admin\BranchPaymentSettingsController::class, 'update'])
            ->name('admin.branches.payment-settings.update');
    });

    // Cash Drawer Reason Configuration
    Route::middleware(['permission:manage_cash_drawer_reasons'])->group(function () {
        Route::get('/admin/cash-drawer-reasons', [\App\Http\Controllers\Admin\CashDrawerReasonController::class, 'index'])
            ->name('admin.cash-drawer-reasons.index');
        Route::post('/admin/cash-drawer-reasons', [\App\Http\Controllers\Admin\CashDrawerReasonController::class, 'store'])
            ->name('admin.cash-drawer-reasons.store');
        Route::put('/admin/cash-drawer-reasons/{reason}', [\App\Http\Controllers\Admin\CashDrawerReasonController::class, 'update'])
            ->name('admin.cash-drawer-reasons.update');
        Route::delete('/admin/cash-drawer-reasons/{reason}', [\App\Http\Controllers\Admin\CashDrawerReasonController::class, 'destroy'])
            ->name('admin.cash-drawer-reasons.destroy');
    });

    // Epic 37: Advanced Promotions & Bundling Engine
    Route::middleware(['permission:manage_promotions'])->group(function () {
        Route::get('/admin/promotions', [\App\Http\Controllers\Admin\PromotionController::class, 'index'])
            ->name('admin.promotions.index');
        Route::post('/admin/promotions', [\App\Http\Controllers\Admin\PromotionController::class, 'store'])
            ->name('admin.promotions.store');
        Route::put('/admin/promotions/{promotion}', [\App\Http\Controllers\Admin\PromotionController::class, 'update'])
            ->name('admin.promotions.update');
        Route::delete('/admin/promotions/{promotion}', [\App\Http\Controllers\Admin\PromotionController::class, 'destroy'])
            ->name('admin.promotions.destroy');
    });

    // Offline Sales Settings — Terminal Sequence Registry (Story 28.5)
    Route::middleware(['permission:manage_offline_sales_settings'])->group(function () {
        Route::get('/admin/sales-machine-profiles', [\App\Http\Controllers\Admin\SalesMachineProfileController::class, 'index'])
            ->name('admin.sales-machine-profiles.index');
        Route::get('/admin/sales-machine-profiles/{salesMachineProfile}/edit', [\App\Http\Controllers\Admin\SalesMachineProfileController::class, 'edit'])
            ->name('admin.sales-machine-profiles.edit');
        Route::put('/admin/sales-machine-profiles/{salesMachineProfile}', [\App\Http\Controllers\Admin\SalesMachineProfileController::class, 'update'])
            ->name('admin.sales-machine-profiles.update');
        Route::post('/admin/sales-machine-profiles/{salesMachineProfile}/activation-code', [\App\Http\Controllers\Admin\SalesMachineProfileController::class, 'generateActivationCode'])
            ->name('admin.sales-machine-profiles.activation-code');
        Route::post('/admin/sales-machine-profiles/{salesMachineProfile}/revoke-activation', [\App\Http\Controllers\Admin\SalesMachineProfileController::class, 'revokeActivation'])
            ->name('admin.sales-machine-profiles.revoke-activation');
        Route::get('/api/admin/sales-machine-profiles/{salesMachineProfile}/offline-status', [\App\Http\Controllers\Admin\SalesMachineProfileController::class, 'offlineStatus'])
            ->name('admin.sales-machine-profiles.offline-status');
    });

    // Statutory discount approval rules (Task 3)
    Route::middleware(['permission:manage_approval_rules'])->group(function () {
        Route::get('/admin/approval-rules', [\App\Http\Controllers\Admin\ApprovalRuleController::class, 'index'])
            ->name('admin.approval-rules.index');
        Route::put('/admin/approval-rules', [\App\Http\Controllers\Admin\ApprovalRuleController::class, 'update'])
            ->name('admin.approval-rules.update');
    });

    // Printer Profile Schema & Admin UI (Task 5)
    Route::middleware(['permission:manage_printer_profiles'])->group(function () {
        Route::resource('/admin/printer-profiles', \App\Http\Controllers\Admin\PrinterProfileController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.printer-profiles');
    });

    // Offline Import Admin Review (Story 28.9)
    Route::middleware(['permission:review_offline_sync_conflicts'])->group(function () {
        Route::get('/api/admin/offline-sync/imports', [\App\Http\Controllers\Admin\OfflineImportController::class, 'index'])
            ->name('admin.offline-sync.imports.index');
        Route::get('/api/admin/offline-sync/imports/{offlineSalesImport}', [\App\Http\Controllers\Admin\OfflineImportController::class, 'show'])
            ->name('admin.offline-sync.imports.show');
        Route::patch('/api/admin/offline-sync/imports/{offlineSalesImport}/review', [\App\Http\Controllers\Admin\OfflineImportController::class, 'review'])
            ->name('admin.offline-sync.imports.review');
        Route::post('/api/admin/offline-sync/imports/{offlineSalesImport}/post', [\App\Http\Controllers\Admin\OfflineImportController::class, 'postImport'])
            ->name('admin.offline-sync.imports.post');

        // Epic 32 - Terminal Sync Monitor
        Route::get('/admin/terminal-sync-monitor', [\App\Http\Controllers\Admin\TerminalSyncMonitorController::class, 'index'])
            ->name('admin.terminal-sync-monitor.index');
        Route::get('/api/admin/terminal-sync-monitor/data', [\App\Http\Controllers\Admin\TerminalSyncMonitorController::class, 'getMonitorData'])
            ->name('admin.terminal-sync-monitor.data');

        // Epic 33 - Prior Period Adjustments
        Route::get('/admin/prior-period-adjustments', [\App\Http\Controllers\Admin\PriorPeriodAdjustmentController::class, 'index'])
            ->name('admin.prior-period-adjustments.index');
        Route::get('/api/admin/prior-period-adjustments/data', [\App\Http\Controllers\Admin\PriorPeriodAdjustmentController::class, 'getAdjustmentsData'])
            ->name('admin.prior-period-adjustments.data');
    });

    // Unit Conversions settings
    Route::middleware(['permission:manage_unit_conversions'])->group(function () {
        Route::get('/inventory/unit-conversions', [\App\Http\Controllers\Inventory\UnitConversionController::class, 'index'])
            ->name('inventory.unit-conversions.index');
        Route::post('/inventory/unit-conversions', [\App\Http\Controllers\Inventory\UnitConversionController::class, 'store'])
            ->name('inventory.unit-conversions.store');
        Route::put('/inventory/unit-conversions/{unitConversion}', [\App\Http\Controllers\Inventory\UnitConversionController::class, 'update'])
            ->name('inventory.unit-conversions.update');
        Route::delete('/inventory/unit-conversions/{unitConversion}', [\App\Http\Controllers\Inventory\UnitConversionController::class, 'destroy'])
            ->name('inventory.unit-conversions.destroy');
    });

    // Variance audit logs reports
    Route::middleware(['permission:view_inventory_reports|audit_inventory'])->group(function () {
        Route::get('/inventory/reports/visibility', [\App\Http\Controllers\Inventory\InventoryVisibilityReportController::class, 'index'])
            ->name('inventory.reports.visibility.index');
        Route::get('/inventory/reports/visibility/export', [\App\Http\Controllers\Inventory\InventoryVisibilityReportController::class, 'export'])
            ->name('inventory.reports.visibility.export');
        Route::get('/inventory/reports/variance-logs', [\App\Http\Controllers\Inventory\VarianceLogController::class, 'index'])
            ->name('inventory.reports.variance-logs.index');
        Route::get('/inventory/reports/variance-logs/export', [\App\Http\Controllers\Inventory\VarianceLogController::class, 'export'])
            ->name('inventory.reports.variance-logs.export');
        Route::get('/inventory/reports/product-composition', [\App\Http\Controllers\Inventory\ProductCompositionReportController::class, 'index'])
            ->name('inventory.reports.product-composition.index');
        Route::get('/inventory/reports/product-composition/export', [\App\Http\Controllers\Inventory\ProductCompositionReportController::class, 'export'])
            ->name('inventory.reports.product-composition.export');
    });
});

Route::post('/pos/timecard/toggle', [\App\Http\Controllers\POS\TimecardController::class, 'toggle'])
    ->middleware(['tenant', 'branch', 'terminal', 'subscription.feature:sales.pos'])
    ->name('pos.timecard.toggle');

Route::get('/api/pos/bootstrap-cache', [\App\Http\Controllers\POS\OfflineReadinessController::class, 'bootstrapCache'])
    ->middleware(['auth', 'tenant', 'branch', 'terminal', 'subscription.feature:sales.pos'])
    ->name('pos.bootstrap-cache');

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
