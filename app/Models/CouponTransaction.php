<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cobro (o reversa, con monto negativo y `reversed_of`) contra un cupón. */
class CouponTransaction extends Model
{
    protected $table = 'coupon_transactions';

    protected $primaryKey = 'transaction_id';

    protected $fillable = [
        'coupon_id',
        'coupon_number',
        'transaction_amount',
        'coupon_type',
        'user_id',
        'device_id',
        'location_id',
        'legacy_device_id',
        'legacy_site_id',
        'ip_address',
        'reversed_of',
    ];
}
