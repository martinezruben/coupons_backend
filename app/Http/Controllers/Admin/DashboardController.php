<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Support\Format;
use Illuminate\View\View;

/** Página de inicio del panel Coupify: indicadores de cupones y de infraestructura. */
class DashboardController extends Controller
{
    public function index(): View
    {
        $today = now()->startOfDay();
        $week = now()->subDays(6)->startOfDay();
        $soon = now()->addDays(30)->toDateString();

        $activeCoupons = fn (string $model) => $model::query()
            ->where('coupon_active', 1)
            ->where(fn ($q) => $q->whereNull('coupon_expire_date')->orWhere('coupon_expire_date', '>=', now()->toDateString()));

        $outstanding = (float) $activeCoupons(Coupon::class)->sum('coupon_balance')
            + (float) $activeCoupons(BarcodeCoupon::class)->sum('coupon_balance');

        $redeemed = fn ($from) => (float) CouponTransaction::query()->where('created_at', '>=', $from)->sum('transaction_amount');

        $expiring = fn (string $model) => $activeCoupons($model)
            ->whereNotNull('coupon_expire_date')->where('coupon_expire_date', '<=', $soon)->where('coupon_balance', '>', 0)->count();

        return view('admin.dashboard-welcome', [
            'kpis' => [
                ['label' => 'Cupones activos', 'value' => (string) ($activeCoupons(Coupon::class)->count() + $activeCoupons(BarcodeCoupon::class)->count())],
                ['label' => 'Saldo pendiente', 'value' => Format::money($outstanding)],
                ['label' => 'Cobrado hoy', 'value' => Format::money($redeemed($today))],
                ['label' => 'Cobrado (7 días)', 'value' => Format::money($redeemed($week))],
                ['label' => 'Cobros hoy', 'value' => (string) CouponTransaction::query()->where('created_at', '>=', $today)->where('transaction_amount', '>', 0)->count()],
                ['label' => 'Por vencer (30 días)', 'value' => (string) ($expiring(Coupon::class) + $expiring(BarcodeCoupon::class))],
            ],
            'stats' => [
                ['label' => 'Usuarios del panel', 'value' => AdminUser::query()->where('is_active', true)->count()],
                ['label' => 'Localidades activas', 'value' => Location::query()->where('is_active', true)->count()],
                ['label' => 'Dispositivos habilitados', 'value' => Device::query()->where('is_enabled', true)->count()],
                ['label' => 'Licencias activas', 'value' => License::query()->where('status', 'ACTIVE')->count()],
            ],
            'recent' => CouponTransaction::query()->with(['user', 'location'])->latest('transaction_id')->limit(10)->get(),
        ]);
    }
}
