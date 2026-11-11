<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register-device', [AuthController::class, 'registerDevice'])->middleware('throttle:30,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'device.operational'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::post('/sync/push', [SyncController::class, 'push'])->middleware('throttle:60,1');
    Route::get('/sync/pull', [SyncController::class, 'pull'])->middleware('throttle:120,1');

    Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/by-location', [ReportController::class, 'byLocation']);
});
