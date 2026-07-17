<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('tenant')->get('/tenant-test', function () {
    return [
        'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId(),
        'name' => app(\App\Services\TenantContext::class)->getTenant()->name,
    ];
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/authenticated-tenant-test', function () {
        return [
            'user_id' => auth()->id(),
            'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId()
        ];
    });

    // RBAC Test Routes
    Route::get('/test/rbac/pos', function () {
        return ['message' => 'POS access granted'];
    })->middleware('permission:access_pos');

    Route::get('/test/rbac/accounting', function () {
        return ['message' => 'Accounting access granted'];
    })->middleware('permission:view_reconciliation_reports');

    Route::get('/test/rbac/admin', function () {
        return ['message' => 'Admin access granted'];
    })->middleware('permission:manage_users');

    // Accounting Outbox Inspection
    Route::prefix('accounting')->group(function () {
        Route::get('/outbox', [\App\Http\Controllers\Accounting\AccountingOutboxController::class, 'index'])
            ->middleware('permission:view_sync_dashboard');
        Route::get('/outbox/{id}', [\App\Http\Controllers\Accounting\AccountingOutboxController::class, 'show'])
            ->middleware('permission:view_sync_dashboard');
    });
});

Route::middleware(['tenant', 'branch'])->get('/branch-test', function () {
    return [
        'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId(),
        'branch_id' => app(\App\Services\BranchContext::class)->getBranchId(),
        'branch_name' => app(\App\Services\BranchContext::class)->getBranch()->name,
    ];
});

// Epic 44 - POS Register Activation
Route::post('/pos/activate', [\App\Http\Controllers\POS\RegisterActivationController::class, 'activate'])
    ->middleware('throttle:5,1')
    ->name('pos.activate');

// Epic 28 Phase 2 — Offline Sync Stub (Story 28.6)
// Returns 503 until reconciliation engine is implemented (Story 28.7+).
Route::middleware(['auth:sanctum', 'tenant', 'branch', 'terminal', 'permission:create_sale', 'subscription.feature:sales.pos'])
    ->post('/pos/offline-sync', [\App\Http\Controllers\POS\OfflineSyncController::class, 'sync'])
    ->name('pos.offline-sync');

Route::middleware(['auth:sanctum', 'tenant', 'branch', 'terminal', 'permission:create_sale', 'subscription.feature:sales.pos'])
    ->prefix('v1/pos/offline-sales')
    ->name('pos.offline-sales.')
    ->group(function () {
        Route::post('/sync', [\App\Http\Controllers\POS\OfflineSyncController::class, 'sync'])
            ->name('sync');
        Route::get('/{offlineTransactionUuid}/sync-status', [\App\Http\Controllers\POS\OfflineSyncController::class, 'status'])
            ->name('sync-status');
    });

// Epic 32 — POS Terminal Sync Diagnostics & Reliability
Route::middleware(['auth:sanctum', 'tenant', 'branch', 'terminal', 'permission:create_sale', 'subscription.feature:sales.pos', 'throttle:60,1'])
    ->prefix('pos')
    ->name('pos.')
    ->group(function () {
        Route::post('/sandbox/validate', [\App\Http\Controllers\POS\SandboxValidationController::class, 'validatePayload'])
            ->name('sandbox.validate');
        Route::get('/submissions/{submission_uuid}', [\App\Http\Controllers\POS\SubmissionLookupController::class, 'show'])
            ->name('submissions.show');
        Route::get('/submissions/sequence/{offline_sequence_number}', [\App\Http\Controllers\POS\SubmissionLookupController::class, 'bySequence'])
            ->name('submissions.by-sequence');
        Route::post('/heartbeat', [\App\Http\Controllers\POS\TerminalHeartbeatController::class, 'store'])
            ->name('heartbeat');

        // Epic 40 — Cash Drawer Audit & Manager Shift Reconciliation
        Route::get('/drawer-status', [\App\Http\Controllers\POS\POSDrawerController::class, 'drawerStatus'])
            ->name('drawer-status');
        Route::post('/shifts/{shift}/spot-audits', [\App\Http\Controllers\POS\POSDrawerController::class, 'spotAudit'])
            ->name('shifts.spot-audits');
        Route::post('/shifts/{shift}/drawer-events', [\App\Http\Controllers\POS\POSDrawerController::class, 'recordEvent'])
            ->name('shifts.drawer-events');

        // Epic 42 — Statutory Discount Engine
        Route::get('/discounts/types', [\App\Http\Controllers\Api\POS\StatutoryDiscountController::class, 'types'])
            ->name('discounts.types');
        Route::post('/discounts/calculate', [\App\Http\Controllers\Api\POS\StatutoryDiscountController::class, 'calculate'])
            ->name('discounts.calculate');
        Route::post('/manager/authorize', [\App\Http\Controllers\POS\ManagerApprovalController::class, 'authorize'])
            ->middleware('throttle:5,1')
            ->name('manager.authorize');
    });

// Epic 30 — System Admin Operational Dashboard (Slice B)
Route::middleware(['auth:sanctum', 'platform.admin'])
    ->prefix('system-admin/dashboard')
    ->name('api.system-admin.dashboard.')
    ->group(function () {
        Route::get('/summary', [\App\Http\Controllers\SystemAdmin\SystemAdminDashboardController::class, 'summary'])
            ->name('summary');
    });
