<?php

use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AdminUserSecurityController;
use App\Http\Controllers\Admin\ApiRequestLogDetailController;
use App\Http\Controllers\Admin\AuditLogDetailController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CouponAdminController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceAccessController;
use App\Http\Controllers\Admin\LocationMapStatusController;
use App\Http\Controllers\Admin\LocationPairingTokenController;
use App\Http\Controllers\Admin\MailTestController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\RoleRbacMatrixController;
use App\Http\Controllers\Admin\ScreenCrudController;
use App\Http\Controllers\Admin\SystemSettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest:admin')->group(function (): void {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');

        // Desafío 2FA (sesión con credenciales ya validadas, sin login pleno)
        Route::get('/2fa', [AdminTwoFactorController::class, 'challenge'])->name('2fa.challenge');
        Route::post('/2fa', [AdminTwoFactorController::class, 'verify'])->name('2fa.verify');
    });

    Route::middleware(['auth:admin', 'admin.active'])->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

        Route::get('/2fa/setup', [AdminTwoFactorController::class, 'show'])->name('2fa.show');
        Route::post('/2fa/setup', [AdminTwoFactorController::class, 'enable'])->name('2fa.enable');
        Route::post('/2fa/disable', [AdminTwoFactorController::class, 'disable'])->name('2fa.disable');

        Route::get('/locations-map/status', [LocationMapStatusController::class, 'index'])->name('locations.map-status');
        Route::get('/locations/{location}/pairing-token', [LocationPairingTokenController::class, 'show'])->name('locations.pairing-token.show');
        Route::post('/locations/{location}/pairing-token', [LocationPairingTokenController::class, 'store'])->name('locations.pairing-token.store');

        Route::post('/devices/{device}/block', [DeviceAccessController::class, 'block'])->name('devices.block');
        Route::post('/devices/{device}/unblock', [DeviceAccessController::class, 'unblock'])->name('devices.unblock');

        Route::get('/api-request-logs/{id}', [ApiRequestLogDetailController::class, 'show'])->name('api-request-logs.show');
        Route::get('/audit-logs/{id}', [AuditLogDetailController::class, 'show'])->name('audit-logs.show');

        Route::post('/admin-users/{adminUser}/reset-2fa', [AdminUserSecurityController::class, 'resetTwoFactor'])->name('admin-users.reset-2fa');
        Route::post('/admin-users/{adminUser}/revoke-sessions', [AdminUserSecurityController::class, 'revokeSessions'])->name('admin-users.revoke-sessions');

        Route::get('/roles', [RoleManagementController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleManagementController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleManagementController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleManagementController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [RoleManagementController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleManagementController::class, 'destroy'])->name('roles.destroy');

        Route::get('/rbac', [RoleRbacMatrixController::class, 'index'])->name('rbac.matrix.index');
        Route::get('/rbac/roles/{role}', [RoleRbacMatrixController::class, 'edit'])->name('rbac.matrix.edit');
        Route::post('/rbac/roles/{role}', [RoleRbacMatrixController::class, 'update'])->name('rbac.matrix.update');

        Route::get('/system-settings', [SystemSettingsController::class, 'edit'])->name('system-settings.edit');
        Route::put('/system-settings', [SystemSettingsController::class, 'update'])->name('system-settings.update');

        Route::post('/mail/validate-smtp', [MailTestController::class, 'validateSmtp'])->name('mail.validate-smtp');
        Route::post('/mail/validate-office365', [MailTestController::class, 'validateOffice365'])->name('mail.validate-office365');
        Route::post('/mail/test', [MailTestController::class, 'sendTest'])->name('mail.test');

        // Cupones: acciones del panel (cada una con su permiso y auditoría)
        Route::get('/coupons/{type}/issue', [CouponAdminController::class, 'issueForm'])->whereIn('type', ['qr', 'barcode'])->name('coupons.issue');
        Route::post('/coupons/{type}/issue', [CouponAdminController::class, 'issue'])->whereIn('type', ['qr', 'barcode'])->name('coupons.issue.store');
        Route::post('/coupons/{type}/{id}/toggle', [CouponAdminController::class, 'toggle'])->whereIn('type', ['qr', 'barcode'])->whereNumber('id')->name('coupons.toggle');
        Route::post('/coupons/{type}/{id}/adjust', [CouponAdminController::class, 'adjust'])->whereIn('type', ['qr', 'barcode'])->whereNumber('id')->name('coupons.adjust');
        Route::post('/coupon-transactions/{id}/reverse', [CouponAdminController::class, 'reverse'])->whereNumber('id')->name('coupon-transactions.reverse');

        Route::get('/screens/{screen}', [ScreenCrudController::class, 'index'])->name('screens.index');
        Route::get('/screens/{screen}/create', [ScreenCrudController::class, 'create'])->name('screens.create');
        Route::post('/screens/{screen}', [ScreenCrudController::class, 'store'])->name('screens.store');
        Route::get('/screens/{screen}/{id}/edit', [ScreenCrudController::class, 'edit'])->name('screens.edit');
        Route::put('/screens/{screen}/{id}', [ScreenCrudController::class, 'update'])->name('screens.update');
        Route::delete('/screens/{screen}/{id}', [ScreenCrudController::class, 'destroy'])->name('screens.destroy');
        // Toggle de status (licenses)
        Route::post('/screens/{screen}/{id}/toggle-status', [ScreenCrudController::class, 'toggleStatus'])->name('screens.toggle-status');
    });
});
