<?php

use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AdminUserSecurityController;
use App\Http\Controllers\Admin\ApiRequestLogDetailController;
use App\Http\Controllers\Admin\AuditLogDetailController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CashClosingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceAccessController;
use App\Http\Controllers\Admin\DeviceLastSyncController;
use App\Http\Controllers\Admin\LocationMapStatusController;
use App\Http\Controllers\Admin\LocationPairingTokenController;
use App\Http\Controllers\Admin\MailTestController;
use App\Http\Controllers\Admin\ProductExcelController;
use App\Http\Controllers\Admin\ProductsMarginExportController;
use App\Http\Controllers\Admin\ProductsNoMovementExportController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\ReportsExportController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\RoleRbacMatrixController;
use App\Http\Controllers\Admin\ScreenCrudController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\TransactionExcelExportController;
use App\Http\Controllers\Admin\TransactionLineItemsController;
use App\Http\Controllers\Admin\TransactionReportExportController;
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
        Route::get('/', [DashboardController::class, 'commercial'])->name('dashboard');
        Route::get('/dashboard/tecnico', [DashboardController::class, 'technical'])->name('dashboard.technical');
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

        Route::get('/2fa/setup', [AdminTwoFactorController::class, 'show'])->name('2fa.show');
        Route::post('/2fa/setup', [AdminTwoFactorController::class, 'enable'])->name('2fa.enable');
        Route::post('/2fa/disable', [AdminTwoFactorController::class, 'disable'])->name('2fa.disable');

        Route::get('/locations-map/status', [LocationMapStatusController::class, 'index'])->name('locations.map-status');
        Route::get('/locations/{location}/pairing-token', [LocationPairingTokenController::class, 'show'])->name('locations.pairing-token.show');
        Route::post('/locations/{location}/pairing-token', [LocationPairingTokenController::class, 'store'])->name('locations.pairing-token.store');

        Route::post('/devices/{device}/block', [DeviceAccessController::class, 'block'])->name('devices.block');
        Route::post('/devices/{device}/unblock', [DeviceAccessController::class, 'unblock'])->name('devices.unblock');
        Route::post('/devices/{device}/reset-last-sync', [DeviceLastSyncController::class, 'store'])->name('devices.reset-last-sync');

        Route::get('/api-request-logs/{id}', [ApiRequestLogDetailController::class, 'show'])->name('api-request-logs.show');
        Route::get('/audit-logs/{id}', [AuditLogDetailController::class, 'show'])->name('audit-logs.show');

        Route::get('/transactions/{transaction}/line-items', [TransactionLineItemsController::class, 'show'])->name('transactions.line-items');
        Route::post('/transactions/excel/export', [TransactionExcelExportController::class, 'export'])->name('transactions.excel.export');
        Route::post('/transactions/report/export', [TransactionReportExportController::class, 'export'])->name('transactions.report.export');

        Route::get('/cierre-caja', [CashClosingController::class, 'index'])->name('cierre-caja.index');
        Route::get('/cierre-caja/export', [CashClosingController::class, 'export'])->name('cierre-caja.export');

        Route::get('/products/excel/export', [ProductExcelController::class, 'export'])->name('products.excel.export');
        Route::post('/products/excel/import', [ProductExcelController::class, 'import'])->name('products.excel.import');

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

        // Reportes prioritarios
        Route::get('/reports/products-best-sellers', [ReportsController::class, 'productsBestSellers'])->name('reports.products-best-sellers');
        Route::get('/reports/products-no-movement', [ReportsController::class, 'productsNoMovement'])->name('reports.products-no-movement');
        Route::get('/reports/products-margin', [ReportsController::class, 'productsMargin'])->name('reports.products-margin');
        Route::get('/reports/payment-methods', [ReportsController::class, 'paymentMethodsReport'])->name('reports.payment-methods');
        Route::get('/reports/users-performance', [ReportsController::class, 'usersPerformanceReport'])->name('reports.users-performance');

        Route::post('/reports/products-no-movement/export', [ProductsNoMovementExportController::class, 'excel'])->name('reports.products-no-movement.export');
        Route::post('/reports/products-no-movement/export-csv', [ProductsNoMovementExportController::class, 'csv'])->name('reports.products-no-movement.export-csv');

        Route::post('/reports/products-margin/export', [ProductsMarginExportController::class, 'excel'])->name('reports.products-margin.export');
        Route::post('/reports/products-margin/export-csv', [ProductsMarginExportController::class, 'csv'])->name('reports.products-margin.export-csv');

        // Exportar reportes Excel
        Route::post('/reports/products-best-sellers/export', [ReportsExportController::class, 'productsBestSellers'])->name('reports.products-best-sellers.export');
        Route::post('/reports/payment-methods/export', [ReportsExportController::class, 'paymentMethods'])->name('reports.payment-methods.export');
        Route::post('/reports/users-performance/export', [ReportsExportController::class, 'usersPerformance'])->name('reports.users-performance.export');

        // Exportar reportes CSV
        Route::post('/reports/products-best-sellers/export-csv', [ReportsExportController::class, 'productsBestSellersCSV'])->name('reports.products-best-sellers.export-csv');
        Route::post('/reports/payment-methods/export-csv', [ReportsExportController::class, 'paymentMethodsCSV'])->name('reports.payment-methods.export-csv');
        Route::post('/reports/users-performance/export-csv', [ReportsExportController::class, 'usersPerformanceCSV'])->name('reports.users-performance.export-csv');

        // Exportar reportes PDF
        Route::post('/reports/products-best-sellers/export-pdf', [ReportsExportController::class, 'productsBestSellersPDF'])->name('reports.products-best-sellers.export-pdf');
        Route::post('/reports/payment-methods/export-pdf', [ReportsExportController::class, 'paymentMethodsPDF'])->name('reports.payment-methods.export-pdf');
        Route::post('/reports/users-performance/export-pdf', [ReportsExportController::class, 'usersPerformancePDF'])->name('reports.users-performance.export-pdf');

        Route::get('/screens/{screen}', [ScreenCrudController::class, 'index'])->name('screens.index');
        Route::get('/screens/{screen}/create', [ScreenCrudController::class, 'create'])->name('screens.create');
        Route::post('/screens/{screen}', [ScreenCrudController::class, 'store'])->name('screens.store');
        Route::get('/screens/{screen}/{id}/edit', [ScreenCrudController::class, 'edit'])->name('screens.edit');
        Route::put('/screens/{screen}/{id}', [ScreenCrudController::class, 'update'])->name('screens.update');
        Route::delete('/screens/{screen}/{id}', [ScreenCrudController::class, 'destroy'])->name('screens.destroy');
        // Toggle de status (licenses/transactions/sync-logs)
        Route::post('/screens/{screen}/{id}/toggle-status', [ScreenCrudController::class, 'toggleStatus'])->name('screens.toggle-status');
    });
});
