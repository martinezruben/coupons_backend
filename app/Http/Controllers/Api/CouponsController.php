<?php

namespace App\Http\Controllers\Api;

use App\Services\CouponService;

/** Cupones QR (requieren coupon_number + coupon_token). */
class CouponsController extends BaseCouponController
{
    protected function service(): CouponService
    {
        return CouponService::qr();
    }
}
