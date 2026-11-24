<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\CouponTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resumen de un periodo de una localidad (lo que entra en un turno): lo cobrado, reversado y recargado, por cajero y por surtidor. El periodo es [desde, hasta): lo que ocurre justo a la hora de corte entra en el turno siguiente.
 * Cobrado = cobros; neto = cobrado menos reversas; las recargas se muestran aparte. Una reversa cuenta en el periodo
 * en que se hizo y se atribuye al cajero del cobro original (así el neto de cada cajero cuadra con lo que vendió).
 */
class CashClose
{
    /**
     * @return array{totals: array<string, float|int|string|null>, cashiers: list<array<string, mixed>>, dispensers: list<array<string, mixed>>}
     */
    public static function summarize(string $locationId, Carbon $from, Carbon $to, ?AdminUser $scopeFor = null): array
    {
        $t = 'coupon_transactions';
        $base = fn () => CouponTransaction::query()->visibleTo($scopeFor)->from($t)
            ->leftJoin("$t as o", 'o.transaction_id', '=', 'coupon_transactions.reversed_of')
            ->where('coupon_transactions.location_id', $locationId)->where('coupon_transactions.created_at', '>=', $from)->where('coupon_transactions.created_at', '<', $to);

        $redeem = "CASE WHEN coupon_transactions.kind = 'REDEEM' THEN coupon_transactions.transaction_amount ELSE 0 END";
        $reverse = "CASE WHEN coupon_transactions.kind = 'REVERSAL' THEN -coupon_transactions.transaction_amount ELSE 0 END";
        $topup = "CASE WHEN coupon_transactions.kind = 'TOPUP' THEN coupon_transactions.transaction_amount ELSE 0 END";

        // Cajero: el del cobro original para las reversas; si lo hizo un administrador, su nombre
        $cashierUser = "CASE WHEN coupon_transactions.kind = 'REVERSAL' THEN o.user_id ELSE coupon_transactions.user_id END";
        $cashierAdmin = "CASE WHEN coupon_transactions.kind = 'REVERSAL' THEN o.admin_user_id ELSE coupon_transactions.admin_user_id END";

        $rows = $base()
            ->selectRaw("$cashierUser as cu, $cashierAdmin as ca")
            ->selectRaw("COALESCE(SUM($redeem), 0) as redeemed, COALESCE(SUM($reverse), 0) as reversed, COALESCE(SUM($topup), 0) as topups")
            ->selectRaw("COUNT(CASE WHEN coupon_transactions.kind = 'REDEEM' THEN 1 END) as redemptions, MIN(coupon_transactions.created_at) as first_at, MAX(coupon_transactions.created_at) as last_at")
            ->groupByRaw("$cashierUser, $cashierAdmin")->get();

        $users = DB::table('users')->whereIn('id', $rows->pluck('cu')->filter()->all())->pluck('username', 'id');
        $admins = DB::table('admin_users')->whereIn('id', $rows->pluck('ca')->filter()->all())->pluck('name', 'id');

        $cashiers = $rows->map(fn ($r) => [
            'name' => $r->cu ? ($users[$r->cu] ?? 'Usuario eliminado') : ($r->ca ? 'Panel: '.($admins[$r->ca] ?? 'Administrador') : 'Sin usuario'),
            'redemptions' => (int) $r->redemptions,
            'redeemed' => round((float) $r->redeemed, 2),
            'reversed' => round((float) $r->reversed, 2),
            'net' => round((float) $r->redeemed - (float) $r->reversed, 2),
            'topups' => round((float) $r->topups, 2),
            'first_at' => $r->first_at ? Carbon::parse($r->first_at)->toDateTimeString() : null,
            'last_at' => $r->last_at ? Carbon::parse($r->last_at)->toDateTimeString() : null,
        ])->sortByDesc('net')->values()->all();

        $dispensers = $base()->whereIn('coupon_transactions.kind', ['REDEEM', 'REVERSAL'])
            ->selectRaw('coupon_transactions.dispenser as dispenser')
            ->selectRaw("COALESCE(SUM($redeem), 0) as redeemed, COALESCE(SUM($reverse), 0) as reversed, COUNT(CASE WHEN coupon_transactions.kind = 'REDEEM' THEN 1 END) as redemptions")
            ->groupBy('coupon_transactions.dispenser')->orderBy('coupon_transactions.dispenser')->get()
            ->map(fn ($r) => [
                'dispenser' => $r->dispenser !== null ? (int) $r->dispenser : null,
                'redemptions' => (int) $r->redemptions,
                'redeemed' => round((float) $r->redeemed, 2),
                'reversed' => round((float) $r->reversed, 2),
                'net' => round((float) $r->redeemed - (float) $r->reversed, 2),
            ])->all();

        $sum = fn (string $k) => round(array_sum(array_column($cashiers, $k)), 2);
        $first = collect($cashiers)->pluck('first_at')->filter()->min();
        $last = collect($cashiers)->pluck('last_at')->filter()->max();

        return [
            'totals' => ['redemptions' => (int) array_sum(array_column($cashiers, 'redemptions')), 'redeemed' => $sum('redeemed'), 'reversed' => $sum('reversed'), 'net' => $sum('net'), 'topups' => $sum('topups'), 'first_at' => $first, 'last_at' => $last],
            'cashiers' => $cashiers,
            'dispensers' => $dispensers,
        ];
    }
}
