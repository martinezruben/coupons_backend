<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Solicitud de fijar el saldo de un cupón: no se aplica hasta que otro administrador la aprueba. */
class AdjustmentRequest extends Model
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $table = 'coupon_adjustment_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
