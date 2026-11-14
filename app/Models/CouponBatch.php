<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponBatch extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'code', 'name', 'coupon_type', 'quantity', 'amount', 'prefix', 'expire_date', 'status', 'generated_count',
        'notes', 'error', 'created_by_admin_id', 'generated_at', 'generation_started_at', 'export_path', 'export_expires_at',
        'export_downloaded_at', 'export_downloads',
    ];

    protected function casts(): array
    {
        return [
            'expire_date' => 'date',
            'generated_at' => 'datetime',
            'generation_started_at' => 'datetime',
            'export_expires_at' => 'datetime',
            'export_downloaded_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ActivationOrder::class, 'batch_id');
    }

    /** Modelo (tabla) de los cupones de este lote según su tipo. */
    public function couponModel(): string
    {
        return $this->coupon_type === 'QRCODE' ? Coupon::class : BarcodeCoupon::class;
    }

    public function coupons()
    {
        return $this->couponModel()::query()->where('batch_id', $this->getKey());
    }

    public function hasExportFile(): bool
    {
        return $this->export_path !== null && ($this->export_expires_at === null || $this->export_expires_at->isFuture());
    }
}
