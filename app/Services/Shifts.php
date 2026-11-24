<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\CouponTransaction;
use App\Models\Location;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turnos por localidad. Cada localidad siempre tiene un turno abierto (se crea solo la primera vez): todo movimiento
 * queda guardado en el turno abierto de su localidad (`coupon_transactions.shift_id`). Cerrar el turno fija la hora de
 * corte: lo anterior a esa hora queda en este turno y lo de ahí en adelante pasa al siguiente, que se abre en ese
 * mismo instante. Se puede cerrar tantas veces como se quiera; cada cierre guarda la foto de lo que entró.
 */
class Shifts
{
    /** Turno abierto de la localidad (lo crea si no existe). */
    public static function current(Location|string $location): Shift
    {
        $id = $location instanceof Location ? $location->getKey() : $location;
        $open = Shift::query()->where('location_id', $id)->where('status', Shift::OPEN)->first();
        if ($open) {
            return $open;
        }

        try {
            return DB::transaction(function () use ($id) {
                // Bloquea la localidad: dos peticiones a la vez no crean dos turnos abiertos
                Location::withTrashed()->whereKey($id)->lockForUpdate()->first();
                if ($again = Shift::query()->where('location_id', $id)->where('status', Shift::OPEN)->first()) {
                    return $again;
                }

                $last = Shift::query()->where('location_id', $id)->orderByDesc('number')->first();
                $startsAt = $last?->ends_at
                    ?? (($first = CouponTransaction::query()->where('location_id', $id)->min('created_at')) ? Carbon::parse($first)->startOfMinute() : now()->startOfMinute());

                $shift = Shift::create(['location_id' => $id, 'number' => ($last?->number ?? 0) + 1, 'starts_at' => $startsAt, 'status' => Shift::OPEN, 'created_at' => now()]);
                // El historial que ya existía sin turno entra en el primero
                CouponTransaction::query()->where('location_id', $id)->whereNull('shift_id')->where('created_at', '>=', $startsAt)->update(['shift_id' => $shift->getKey()]);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            return Shift::query()->where('location_id', $id)->where('status', Shift::OPEN)->firstOrFail();
        }
    }

    /** Id del turno abierto para guardarlo en un movimiento nuevo; null si no hay localidad o algo falla (nunca rompe una venta). */
    public static function currentId(?string $locationId): ?int
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }

        try {
            return (int) self::current($locationId)->getKey();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Lo que entraría al cerrar el turno a la hora de corte `$until` y lo que quedaría para el siguiente.
     *
     * @return array{summary: array<string, mixed>, next: array{count: int, amount: float}, from: Carbon, until: Carbon}
     */
    public static function preview(Shift $shift, Carbon $until, ?AdminUser $scopeFor = null): array
    {
        $summary = CashClose::summarize($shift->location_id, $shift->starts_at, $until, $scopeFor);
        $after = CouponTransaction::query()->visibleTo($scopeFor)->where('location_id', $shift->location_id)->where('created_at', '>=', $until)->where('kind', CouponTransaction::KIND_REDEEM);

        return [
            'summary' => $summary,
            'next' => ['count' => (clone $after)->count(), 'amount' => round((float) (clone $after)->sum('transaction_amount'), 2)],
            'from' => $shift->starts_at, 'until' => $until,
        ];
    }

    /**
     * Cierra el turno abierto de la localidad a la hora de corte indicada y abre el siguiente a partir de ella.
     *
     * @throws \InvalidArgumentException con el motivo, para mostrarlo
     */
    public static function close(Location $location, Carbon $until, ?string $note, AdminUser $admin): Shift
    {
        $until = $until->copy()->startOfMinute();
        if ($until->isFuture()) {
            throw new \InvalidArgumentException('La hora de corte no puede ser futura.');
        }

        return DB::transaction(function () use ($location, $until, $note, $admin) {
            $shift = self::current($location);
            $shift = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->first();
            if (! $shift->isOpen()) {
                throw new \InvalidArgumentException('Este turno ya se cerró.');
            }
            if ($until->lte($shift->starts_at)) {
                throw new \InvalidArgumentException('La hora de corte debe ser posterior al inicio del turno ('.$shift->starts_at->format('d/m/Y H:i').').');
            }

            $s = CashClose::summarize($location->getKey(), $shift->starts_at, $until);
            $shift->update([
                'ends_at' => $until, 'status' => Shift::CLOSED, 'closed_by_admin_id' => $admin->getKey(), 'closed_at' => now(), 'note' => $note,
                'redeemed' => $s['totals']['redeemed'], 'reversed' => $s['totals']['reversed'], 'net' => $s['totals']['net'], 'topups' => $s['totals']['topups'],
                'redemptions' => $s['totals']['redemptions'], 'details' => ['cashiers' => $s['cashiers'], 'dispensers' => $s['dispensers']],
            ]);

            $next = Shift::create(['location_id' => $location->getKey(), 'number' => $shift->number + 1, 'starts_at' => $until, 'status' => Shift::OPEN, 'created_at' => now()]);

            // Lo anterior a la hora de corte queda en este turno; lo de ahí en adelante pasa al siguiente
            CouponTransaction::query()->where('location_id', $location->getKey())->where('created_at', '<', $until)->where('created_at', '>=', $shift->starts_at)->update(['shift_id' => $shift->getKey()]);
            CouponTransaction::query()->where('location_id', $location->getKey())->where('created_at', '>=', $until)->update(['shift_id' => $next->getKey()]);

            return $shift;
        });
    }
}
