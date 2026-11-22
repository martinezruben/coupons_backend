<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\CashClosing;
use App\Models\CouponTransaction;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cierre de caja de una localidad: lo cobrado, reversado y recargado en un periodo, por cajero y por surtidor.
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
            ->where('coupon_transactions.location_id', $locationId)->whereBetween('coupon_transactions.created_at', [$from, $to]);

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

    /** Fin del último cierre de la localidad: el siguiente turno empieza ahí. */
    public static function lastClosedAt(string $locationId): ?Carbon
    {
        $v = CashClosing::query()->where('location_id', $locationId)->max('period_to');

        return $v ? Carbon::parse($v) : null;
    }

    /**
     * Guarda el cierre. No admite periodos que se crucen con otro cierre de la misma localidad ni que terminen en el futuro.
     *
     * @throws \InvalidArgumentException con el motivo, para mostrarlo
     */
    public static function close(Location $location, Carbon $from, Carbon $to, ?string $note, AdminUser $admin): CashClosing
    {
        if ($to->isFuture()) {
            throw new \InvalidArgumentException('El periodo no puede terminar en el futuro.');
        }
        if ($to->lte($from)) {
            throw new \InvalidArgumentException('La hora final debe ser posterior a la inicial.');
        }

        return DB::transaction(function () use ($location, $from, $to, $note, $admin) {
            // Bloquea la localidad para que dos cierres simultáneos no se crucen
            Location::query()->whereKey($location->getKey())->lockForUpdate()->first();
            $overlap = CashClosing::query()->where('location_id', $location->getKey())->where('period_from', '<', $to)->where('period_to', '>', $from)->first();
            if ($overlap) {
                throw new \InvalidArgumentException('Ya existe un cierre de esta localidad que cubre parte de ese periodo ('.$overlap->period_from->format('d/m/Y H:i').' a '.$overlap->period_to->format('d/m/Y H:i').').');
            }

            $s = self::summarize($location->getKey(), $from, $to);

            return CashClosing::create([
                'location_id' => $location->getKey(), 'period_from' => $from, 'period_to' => $to,
                'redeemed' => $s['totals']['redeemed'], 'reversed' => $s['totals']['reversed'], 'net' => $s['totals']['net'], 'topups' => $s['totals']['topups'],
                'redemptions' => $s['totals']['redemptions'], 'details' => ['cashiers' => $s['cashiers'], 'dispensers' => $s['dispensers']],
                'note' => $note, 'closed_by_admin_id' => $admin->getKey(), 'created_at' => now(),
            ]);
        });
    }
}
