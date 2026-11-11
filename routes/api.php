<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register-device', [AuthController::class, 'registerDevice'])->middleware('throttle:30,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'device.operational'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
});
