<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cupón de código de barras (solo coupon_number). */
class BarcodeCoupon extends Model
{
    protected $table = 'coupons_codbar';

    protected $primaryKey = 'coupon_id';

    public $timestamps = false;

    protected $fillable = ['coupon_balance'];
}
