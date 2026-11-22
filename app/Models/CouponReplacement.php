<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Solicitud de reemplazo de un cupón perdido. */
class CouponReplacement extends Model
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $table = 'coupon_replacements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** Tipo de ruta del panel (`qr` / `barcode`). */
    public function routeType(): string
    {
        return $this->coupon_type === 'QRCODE' ? 'qr' : 'barcode';
    }
}
