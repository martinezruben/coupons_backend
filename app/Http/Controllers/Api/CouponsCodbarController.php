<?php

namespace App\Http\Controllers\Api;

use App\Services\CouponService;

/** Cupones de código de barras (solo coupon_number). */
class CouponsCodbarController extends BaseCouponController
{
    protected function service(): CouponService
    {
        return CouponService::barcode();
    }
}
