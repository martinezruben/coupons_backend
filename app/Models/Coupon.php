<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCustomers;
use App\Support\CouponToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cupón QR (requiere coupon_number + coupon_token). */
class Coupon extends Model
{
    use ScopedToCustomers;

    protected $table = 'coupons';

    protected $primaryKey = 'coupon_id';

    public $timestamps = false;

    protected $fillable = ['coupon_balance'];

    protected $hidden = ['coupon_token', 'coupon_token_enc'];

    // Ciclo de vida: GENERATED -> ASSIGNED -> ACTIVE (+ INACTIVE, VOID). `coupon_active` = 1 solo si ACTIVE.
    public const STATUS_GENERATED = 'GENERATED';

    public const STATUS_ASSIGNED = 'ASSIGNED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_VOID = 'VOID';

    /**
     * Token en claro para dibujar el QR impreso: el cifrado (cupones nuevos) o, en cupones heredados que aún lo
     * tienen en claro, el propio `coupon_token`. null si solo existe su hash.
     */
    public function plainToken(): ?string
    {
        $fromEnc = CouponToken::reveal($this->coupon_token_enc);
        if ($fromEnc !== null) {
            return $fromEnc;
        }

        return $this->coupon_token && ! CouponToken::isHashed($this->coupon_token) ? $this->coupon_token : null;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
