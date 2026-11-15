<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\CouponTransaction;
use App\Models\TopUpRule;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Evalúa las reglas de recarga activas (tabla topup_rules) para un cupón y un momento dado.
 * Sin reglas de un tipo, ese tipo no restringe nada.
 */
class TopUpRules
{
    private const DAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /**
     * @param  bool  $enforceLocation  false en recargas del panel, que no tienen localidad
     *
     * @throws CouponException
     */
    public static function assertAllowed(string $couponType, BigDecimal $amount, string $couponNumber, ?string $locationId, bool $enforceLocation, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        $rules = TopUpRule::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('coupon_type')->orWhere('coupon_type', $couponType))
            ->get();

        self::assertModernRules($rules->where('type', TopUpRule::MODERN), $couponType, $amount, $couponNumber, $locationId, $enforceLocation, $now);
        $rules = $rules->where('type', '!=', TopUpRule::MODERN);

        $weekday = $rules->where('type', 'weekday');
        if ($weekday->isNotEmpty()) {
            $allowed = $weekday->flatMap(fn (TopUpRule $r) => $r->weekdayList())->unique()->sort()->values()->all();
            if (! in_array($now->dayOfWeekIso, $allowed, true)) {
                throw new CouponException('Top-ups are not allowed on this weekday', 422, [
                    'allowed_weekdays' => array_map(fn (int $d) => self::DAYS[$d], $allowed),
                ]);
            }
        }

        $location = $rules->where('type', 'location');
        if ($enforceLocation && $location->isNotEmpty()) {
            $allowed = $location->pluck('location_id')->filter()->map(fn ($id) => (string) $id)->all();
            if ($locationId === null || ! in_array((string) $locationId, $allowed, true)) {
                throw new CouponException('Top-ups are not allowed at this location', 422);
            }
        }

        $daily = $rules->where('type', 'daily_amount')
            ->filter(fn (TopUpRule $r) => $r->max_daily_amount !== null && ($r->location_id === null || (string) $r->location_id === (string) $locationId));
        if ($daily->isNotEmpty()) {
            $limit = $daily->map(fn (TopUpRule $r) => BigDecimal::of((string) $r->max_daily_amount))
                ->reduce(fn (?BigDecimal $min, BigDecimal $v) => $min === null || $v->isLessThan($min) ? $v : $min);

            $already = BigDecimal::of((string) (CouponTransaction::query()
                ->where('kind', CouponTransaction::KIND_TOPUP)
                ->where('coupon_number', $couponNumber)
                ->where('coupon_type', $couponType)
                ->where('created_at', '>=', $now->copy()->startOfDay())
                ->sum('transaction_amount') ?: '0'));

            if ($already->plus($amount)->isGreaterThan($limit)) {
                $remaining = $limit->minus($already);
                throw new CouponException('Daily top-up limit exceeded', 422, [
                    'daily_limit' => (string) $limit->toScale(2),
                    'topped_up_today' => (string) $already->toScale(2),
                    'remaining' => (string) ($remaining->isNegative() ? BigDecimal::zero() : $remaining)->toScale(2),
                ]);
            }
        }
    }

    /**
     * Reglas con varias condiciones: cada regla exige que se cumplan TODAS sus condiciones y basta con que
     * UNA regla se cumpla completa para permitir la recarga.
     *
     * @param  Collection<int, TopUpRule>  $rules
     *
     * @throws CouponException
     */
    private static function assertModernRules($rules, string $couponType, BigDecimal $amount, string $couponNumber, ?string $locationId, bool $enforceLocation, CarbonInterface $now): void
    {
        if ($rules->isEmpty()) {
            return;
        }

        $failures = [];
        foreach ($rules as $rule) {
            $failure = self::failure($rule, $couponType, $amount, $couponNumber, $locationId, $enforceLocation, $now);
            if ($failure === null) {
                return;
            }
            $failures[$rule->name] = $failure;
        }

        if (count($failures) === 1) {
            $first = array_values($failures)[0];
            throw new CouponException($first['message'], 422, $first['details']);
        }

        throw new CouponException('No top-up rule allows this operation', 422, ['rules' => array_map(fn ($f) => $f['message'], $failures)]);
    }

    /** @return array{message: string, details: array<string, mixed>}|null */
    private static function failure(TopUpRule $rule, string $couponType, BigDecimal $amount, string $couponNumber, ?string $locationId, bool $enforceLocation, CarbonInterface $now): ?array
    {
        if ($days = $rule->weekdayList()) {
            if (! in_array($now->dayOfWeekIso, $days, true)) {
                return ['message' => 'Top-ups are not allowed on this weekday', 'details' => ['allowed_weekdays' => array_map(fn (int $d) => self::DAYS[$d], $days)]];
            }
        }

        if ($rule->time_from && $rule->time_to) {
            $time = $now->format('H:i');
            $inside = $rule->time_from <= $rule->time_to
                ? ($time >= $rule->time_from && $time <= $rule->time_to)
                : ($time >= $rule->time_from || $time <= $rule->time_to); // ventana que cruza la medianoche
            if (! $inside) {
                return ['message' => 'Top-ups are not allowed at this time of day', 'details' => ['allowed_from' => $rule->time_from, 'allowed_to' => $rule->time_to]];
            }
        }

        if ($enforceLocation && ($ids = $rule->locationIdList())) {
            if ($locationId === null || ! in_array((string) $locationId, $ids, true)) {
                return ['message' => 'Top-ups are not allowed at this location', 'details' => []];
            }
        }

        if ($rule->max_per_topup !== null && $amount->isGreaterThan(BigDecimal::of((string) $rule->max_per_topup))) {
            return ['message' => 'Top-up amount exceeds the per-top-up limit', 'details' => ['max_per_topup' => (string) BigDecimal::of((string) $rule->max_per_topup)->toScale(2)]];
        }

        if ($rule->max_daily_amount !== null) {
            $limit = BigDecimal::of((string) $rule->max_daily_amount);
            $already = BigDecimal::of((string) (CouponTransaction::query()
                ->where('kind', CouponTransaction::KIND_TOPUP)->where('coupon_number', $couponNumber)->where('coupon_type', $couponType)
                ->where('created_at', '>=', $now->copy()->startOfDay())->sum('transaction_amount') ?: '0'));
            if ($already->plus($amount)->isGreaterThan($limit)) {
                $remaining = $limit->minus($already);

                return ['message' => 'Daily top-up limit exceeded', 'details' => [
                    'daily_limit' => (string) $limit->toScale(2),
                    'topped_up_today' => (string) $already->toScale(2),
                    'remaining' => (string) ($remaining->isNegative() ? BigDecimal::zero() : $remaining)->toScale(2),
                ]];
            }
        }

        return null;
    }
}
