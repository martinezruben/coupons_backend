<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CouponAuthController;
use App\Http\Controllers\Api\CouponsCodbarController;
use App\Http\Controllers\Api\CouponsController;
use App\Http\Controllers\Api\CouponTransactionsController;
use App\Http\Controllers\Api\SalesController;
use Illuminate\Support\Facades\Route;

// --- Dispositivos (emparejamiento y licencia) ---
Route::post('/auth/register-device', [AuthController::class, 'registerDevice'])->middleware('throttle:30,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'device.operational'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// --- App de cupones (usuarios de la app; mismas rutas que la API anterior) ---
Route::post('/login', [CouponAuthController::class, 'login'])->middleware(['app.version', 'throttle:coupon-login']);

Route::middleware(['app.version', 'auth:sanctum', 'coupon.user', 'throttle:coupon-api'])->group(function (): void {
    Route::get('/profile', [CouponAuthController::class, 'profile']);
    Route::post('/logout', [CouponAuthController::class, 'logout']);

    // QR
    Route::get('/balance', [CouponsController::class, 'balance'])
        ->name('balance')->middleware(['coupon.device', 'abilities:coupon:read', 'throttle:coupon-lookup']);
    Route::post('/transaction', [CouponsController::class, 'transaction'])
        ->middleware(['coupon.device', 'abilities:coupon:redeem', 'throttle:coupon-redeem', 'idempotent']);

    // Ventas recientes (reimprimir, conciliar, recuperar una venta cuya respuesta se perdió)
    Route::get('/sales/recent', [SalesController::class, 'recent'])
        ->name('sales.recent')->middleware(['coupon.device', 'abilities:coupon:redeem', 'throttle:coupon-lookup']);

    // Código de barras
    Route::get('/balancecb', [CouponsCodbarController::class, 'balance'])
        ->name('balancecb')->middleware(['coupon.device', 'abilities:coupon:read', 'throttle:coupon-lookup']);
    Route::post('/transactioncb', [CouponsCodbarController::class, 'transaction'])
        ->middleware(['coupon.device', 'abilities:coupon:redeem', 'throttle:coupon-redeem', 'idempotent']);

    // Recargas (TOP-UP) sujetas a las reglas de topup_rules (MANAGER / ADMIN)
    Route::post('/topup', [CouponsController::class, 'topUp'])
        ->name('topup')->middleware(['coupon.device', 'abilities:coupon:topup', 'throttle:coupon-redeem', 'idempotent']);
    Route::post('/topupcb', [CouponsCodbarController::class, 'topUp'])
        ->name('topupcb')->middleware(['coupon.device', 'abilities:coupon:topup', 'throttle:coupon-redeem', 'idempotent']);

    // Reversa auditada (MANAGER / ADMIN)
    Route::post('/transactions/{transaction}/reverse', [CouponTransactionsController::class, 'reverse'])
        ->middleware(['coupon.device', 'abilities:coupon:reverse', 'throttle:coupon-restricted']);

    // Fijar saldo manualmente (solo ADMIN, con motivo y auditoría)
    Route::post('/restore', [CouponsController::class, 'restore'])
        ->middleware(['coupon.device', 'abilities:coupon:restore', 'throttle:coupon-restricted']);
    Route::post('/restorecb', [CouponsCodbarController::class, 'restore'])
        ->middleware(['coupon.device', 'abilities:coupon:restore', 'throttle:coupon-restricted']);
});
