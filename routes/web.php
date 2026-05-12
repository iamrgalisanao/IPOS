<?php

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

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    // Accounting integration routes
    Route::prefix('accounting/quickbooks')->name('accounting.quickbooks.')->group(function () {
        Route::get('/connect', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'connect'])
            ->name('connect');
        Route::get('/callback', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'callback'])
            ->name('callback');
        Route::post('/disconnect', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'disconnect'])
            ->name('disconnect');
        Route::get('/status', [\App\Http\Controllers\Accounting\QuickBooksConnectionController::class, 'status'])
            ->name('status');
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

    // POS Routes
    Route::get('/pos', [\App\Http\Controllers\POSController::class, 'index'])->name('pos.index');
    Route::get('/pos/search', [\App\Http\Controllers\POSController::class, 'search'])->name('pos.search');

    // Checkout Validation: requires branch context + create_sale permission
    Route::middleware(['branch', 'permission:create_sale'])->group(function () {
        Route::post('/pos/checkout/validate', [\App\Http\Controllers\CheckoutController::class, 'validateDraft'])
             ->name('pos.checkout.validate');

        Route::post('/pos/checkout/create-sale', [\App\Http\Controllers\CheckoutController::class, 'createSale'])
             ->name('pos.checkout.create-sale');

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
});

require __DIR__.'/auth.php';
