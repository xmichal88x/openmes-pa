<?php

use Illuminate\Support\Facades\Route;
use Modules\PlPackagingExtras\Http\Controllers\HealthController;
use Modules\PlPackagingExtras\Http\Controllers\PackagingApiController;
use Modules\PlPackagingExtras\Http\Controllers\PackagingWorkOrderController;
use Modules\PlPackagingExtras\Http\Controllers\WorkOrderStartController;

/*
 * The module's route file is not part of withRouting(api: ...), so the `api`
 * middleware group has to be added explicitly. URIs carry the full api/v1
 * prefix for the same reason.
 */

Route::middleware('api')
    ->get('api/v1/pl-packaging/health', HealthController::class)
    ->name('pl-packaging.health');

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::prefix('api/v1/packaging')->name('pl-packaging.')->group(function () {
        Route::middleware('role:Operator|Supervisor|Admin|PackingWorker|PackingBridge')->group(function () {
            Route::get('/items', [PackagingApiController::class, 'items'])->name('items');
            Route::get('/stats', [PackagingApiController::class, 'stats'])->name('stats');
            Route::get('/eans', [PackagingApiController::class, 'listEans'])->name('eans.index');
            Route::get('/eans/{workOrderEan}', [PackagingApiController::class, 'showEan'])->name('eans.show');
            Route::post('/eans', [PackagingApiController::class, 'storeEan'])->name('eans.store');
            Route::delete('/eans/{workOrderEan}', [PackagingApiController::class, 'destroyEan'])->name('eans.destroy');
            Route::get('/scan-logs', [PackagingApiController::class, 'scanLogs'])->name('scan-logs');
            Route::post('/scan', [PackagingApiController::class, 'scan'])->name('scan');
            Route::put('/expected-codes/{workOrder}', [PackagingWorkOrderController::class, 'expectedCodes'])->name('expected-codes');
            Route::post('/work-orders', [PackagingWorkOrderController::class, 'createWorkOrder'])->name('work-orders.store');
            Route::post('/complete', [PackagingWorkOrderController::class, 'complete'])->name('complete');
        });
    });

    Route::post('api/v1/work-orders/{workOrder}/start', [WorkOrderStartController::class, 'start'])
        ->middleware('role:Operator|Supervisor|Admin|PackingWorker|PackingBridge')
        ->name('pl-packaging.work-orders.start');
});
