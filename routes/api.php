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

// Epic 28 Phase 2 — Offline Sync Stub (Story 28.6)
// Returns 503 until reconciliation engine is implemented (Story 28.7+).
Route::middleware(['auth:sanctum', 'tenant', 'branch', 'permission:create_sale', 'subscription.feature:sales.pos'])
    ->post('/pos/offline-sync', [\App\Http\Controllers\POS\OfflineSyncController::class, 'sync'])
    ->name('pos.offline-sync');

// Epic 30 — System Admin Operational Dashboard (Slice B)
Route::middleware(['auth:sanctum', 'platform.admin'])
    ->prefix('system-admin/dashboard')
    ->name('api.system-admin.dashboard.')
    ->group(function () {
        Route::get('/summary', [\App\Http\Controllers\SystemAdmin\SystemAdminDashboardController::class, 'summary'])
            ->name('summary');
    });
