<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Operación de asignar o activar una selección de cupones de un lote para un cliente. */
class ActivationOrder extends Model
{
    use HasUuidPrimaryKey;

    public const MODE_ASSIGN = 'assign';

    public const MODE_NOW = 'now';

    public const MODE_SCHEDULED = 'scheduled';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'batch_id', 'customer_id', 'mode', 'activate_at', 'status', 'selection_type', 'selection', 'coupons_count',
        'reason', 'requested_by_admin_id', 'applied_at', 'cancelled_at', 'cancelled_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'activate_at' => 'datetime',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'selection' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_id');
    }
}
