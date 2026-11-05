<?php

use Illuminate\Support\Facades\Route;
use Modules\PipelineDashboard\Http\Controllers\OrderDetailController;
use Modules\PipelineDashboard\Http\Controllers\PipelineController;
use Modules\PipelineDashboard\Models\NestingStatus;
use Modules\PipelineDashboard\Models\PipelineLog;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/modules/pipeline', [PipelineController::class, 'index'])
        ->name('pipeline.index');
    Route::get('/modules/pipeline/order/{orderNo}', [OrderDetailController::class, 'show'])
        ->name('pipeline.order');
});

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware(['throttle:60,1'])
    ->group(function () {
    Route::post('/api/pipeline/webhook', function (\Illuminate\Http\Request $request) {
        $secret = env('PIPELINE_WEBHOOK_SECRET', '');
        if ($secret !== '' && $request->header('X-Pipeline-Secret') !== $secret) {
            abort(403, 'Invalid webhook secret');
        }

        $validated = $request->validate([
            'order_no' => 'required|string|max:64',
            'stage' => 'required|string|max:32',
            'details' => 'sometimes|array',
        ]);

        NestingStatus::updateOrCreate(
            ['order_no' => $validated['order_no']],
            [
                'status' => $validated['stage'],
                'details' => $validated['details'] ?? [],
                'updated_at' => now(),
            ]
        );

        PipelineLog::create([
            'order_no' => $validated['order_no'],
            'stage' => $validated['stage'],
            'details' => $validated['details'] ?? [],
            'source' => 'webhook_nesting',
            'logged_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    });
});
