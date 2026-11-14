<?php

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

/**
 * Consultas agregadas compartidas por el dashboard y los reportes de cupones. Todas respetan el alcance por
 * cliente del administrador: con alcance restringido solo cuentan los cupones y movimientos de sus clientes.
 */
final class CouponStats
{
    public const TABLES = ['QRCODE' => 'coupons', 'CODBAR' => 'coupons_codbar'];

    /**
     * Cupones por estado (cantidad y saldo), con filtro opcional de cliente o lote.
     *
     * @return array<string, array{count: int, balance: float}>
     */
    public static function byStatus(?AdminUser $admin, ?string $customerId = null, ?string $couponType = null): array
    {
        $out = [];
        foreach (self::TABLES as $type => $table) {
            if ($couponType !== null && $couponType !== $type) {
                continue;
            }
            $rows = DB::table($table)
                ->when($admin?->allowedCustomerIds() !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('customer_id', $admin->allowedCustomerIds())->orWhere(fn ($g) => $g->where('status', 'GENERATED')->whereNull('customer_id'))))
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(coupon_balance), 0) as bal')->groupBy('status')->get();
            foreach ($rows as $r) {
                $out[$r->status] ??= ['count' => 0, 'balance' => 0.0];
                $out[$r->status]['count'] += (int) $r->n;
                $out[$r->status]['balance'] += (float) $r->bal;
            }
        }

        return $out;
    }

    /** Condición para limitar movimientos (`coupon_transactions`) al alcance del administrador y a un cliente. */
    public static function transactions(?AdminUser $admin, ?string $customerId = null)
    {
        return DB::table('coupon_transactions')
            ->when($admin?->allowedCustomerIds() !== null, fn ($q) => $q->whereIn('customer_id', $admin->allowedCustomerIds()))
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId));
    }
}
