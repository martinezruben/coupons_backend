<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\CouponTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;

/** Límites de velocidad de cobros: por cupón, por usuario y por localidad (por día), y monto que exige supervisor. */
class CouponLimits
{
    /**
     * @param  list<string>  $numbers
     *
     * @throws CouponException con `limit` en el detalle para que el llamador registre la alerta
     */
    public static function assertRedeemAllowed(array $numbers, BigDecimal $amount, ?User $user, ?string $locationId): void
    {
        $cfg = config('coupons.limits');

        $large = BigDecimal::of((string) ($cfg['large_redeem_threshold'] ?: '0'));
        if ($large->isPositive() && $amount->isGreaterThan($large) && $user && ! $user->tokenCan('coupon:redeem_large')) {
            throw new CouponException('Supervisor approval required for this amount', 403, ['limit' => 'large_redeem', 'threshold' => (string) $large->toScale(2)]);
        }

        $start = now()->startOfDay();
        $today = fn () => CouponTransaction::query()->where('kind', CouponTransaction::KIND_REDEEM)->where('created_at', '>=', $start);

        $perCoupon = (int) $cfg['per_coupon_daily_count'];
        if ($perCoupon > 0) {
            foreach ($numbers as $number) {
                if ($today()->where('coupon_number', $number)->count() >= $perCoupon) {
                    throw new CouponException('Daily limit of redemptions for this coupon reached', 429, ['limit' => 'per_coupon_daily_count', 'coupon_number' => $number]);
                }
            }
        }

        $perUser = BigDecimal::of((string) ($cfg['per_user_daily_amount'] ?: '0'));
        if ($user && $perUser->isPositive()) {
            $sum = BigDecimal::of((string) ($today()->where('user_id', $user->getKey())->sum('transaction_amount') ?: '0'));
            if ($sum->plus($amount)->isGreaterThan($perUser)) {
                throw new CouponException('Daily amount limit for this user reached', 429, ['limit' => 'per_user_daily_amount']);
            }
        }

        $perLocation = BigDecimal::of((string) ($cfg['per_location_daily_amount'] ?: '0'));
        if ($locationId && $perLocation->isPositive()) {
            $sum = BigDecimal::of((string) ($today()->where('location_id', $locationId)->sum('transaction_amount') ?: '0'));
            if ($sum->plus($amount)->isGreaterThan($perLocation)) {
                throw new CouponException('Daily amount limit for this location reached', 429, ['limit' => 'per_location_daily_amount']);
            }
        }
    }
}
