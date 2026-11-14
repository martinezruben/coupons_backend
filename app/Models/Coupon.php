<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cupón QR (requiere coupon_number + coupon_token). */
class Coupon extends Model
{
    protected $table = 'coupons';

    protected $primaryKey = 'coupon_id';

    public $timestamps = false;

    protected $fillable = ['coupon_balance'];

    protected $hidden = ['coupon_token'];

    // Ciclo de vida: GENERATED -> ASSIGNED -> ACTIVE (+ INACTIVE, VOID). `coupon_active` = 1 solo si ACTIVE.
    public const STATUS_GENERATED = 'GENERATED';

    public const STATUS_ASSIGNED = 'ASSIGNED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_VOID = 'VOID';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
