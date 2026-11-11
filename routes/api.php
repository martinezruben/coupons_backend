<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CouponAuthController;
use App\Http\Controllers\Api\CouponsCodbarController;
use App\Http\Controllers\Api\CouponsController;
use App\Http\Controllers\Api\CouponTransactionsController;
use Illuminate\Support\Facades\Route;

// --- Dispositivos (emparejamiento y licencia) ---
Route::post('/auth/register-device', [AuthController::class, 'registerDevice'])->middleware('throttle:30,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'device.operational'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// --- App de cupones (usuarios de la app; mismas rutas que la API anterior) ---
Route::post('/login', [CouponAuthController::class, 'login'])->middleware('throttle:coupon-login');

Route::middleware(['auth:sanctum', 'coupon.user', 'throttle:coupon-api'])->group(function (): void {
    Route::get('/profile', [CouponAuthController::class, 'profile']);
    Route::post('/logout', [CouponAuthController::class, 'logout']);

    // QR
    Route::get('/balance', [CouponsController::class, 'balance'])
        ->name('balance')->middleware(['abilities:coupon:read', 'throttle:coupon-lookup']);
    Route::post('/transaction', [CouponsController::class, 'transaction'])
        ->middleware(['abilities:coupon:redeem', 'throttle:coupon-redeem', 'idempotent']);

    // Código de barras
    Route::get('/balancecb', [CouponsCodbarController::class, 'balance'])
        ->name('balancecb')->middleware(['abilities:coupon:read', 'throttle:coupon-lookup']);
    Route::post('/transactioncb', [CouponsCodbarController::class, 'transaction'])
        ->middleware(['abilities:coupon:redeem', 'throttle:coupon-redeem', 'idempotent']);

    // Reversa auditada (MANAGER / ADMIN)
    Route::post('/transactions/{transaction}/reverse', [CouponTransactionsController::class, 'reverse'])
        ->middleware(['abilities:coupon:reverse', 'throttle:coupon-restricted']);

    // Fijar saldo manualmente (solo ADMIN, con motivo y auditoría)
    Route::post('/restore', [CouponsController::class, 'restore'])
        ->middleware(['abilities:coupon:restore', 'throttle:coupon-restricted']);
    Route::post('/restorecb', [CouponsCodbarController::class, 'restore'])
        ->middleware(['abilities:coupon:restore', 'throttle:coupon-restricted']);
});
