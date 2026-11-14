<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCustomers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cobro (o reversa, con monto negativo y `reversed_of`) contra un cupón. */
class CouponTransaction extends Model
{
    use ScopedToCustomers;

    public const KIND_REDEEM = 'REDEEM';

    public const KIND_TOPUP = 'TOPUP';

    public const KIND_REVERSAL = 'REVERSAL';

    protected $table = 'coupon_transactions';

    protected $primaryKey = 'transaction_id';

    protected $fillable = [
        'coupon_id',
        'coupon_number',
        'transaction_amount',
        'coupon_type',
        'user_id',
        'admin_user_id',
        'customer_id',
        'device_id',
        'location_id',
        'legacy_device_id',
        'legacy_site_id',
        'ip_address',
        'reversed_of',
        'kind',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
