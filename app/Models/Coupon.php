<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cupón QR (requiere coupon_number + coupon_token). */
class Coupon extends Model
{
    protected $table = 'coupons';

    protected $primaryKey = 'coupon_id';

    public $timestamps = false;

    protected $fillable = ['coupon_balance'];

    protected $hidden = ['coupon_token'];
}
