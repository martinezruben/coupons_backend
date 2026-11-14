<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de recarga (TOP-UP). Tipos:
 * - location: las recargas solo se permiten en las localidades de las reglas activas.
 * - weekday: las recargas solo se permiten en los días de la semana de las reglas activas.
 * - daily_amount: tope de lo que se puede recargar a un mismo cupón en un día.
 * Con `coupon_type` nulo la regla aplica a cupones QR y de barras.
 */
class TopUpRule extends Model
{
    public const TYPES = ['location', 'weekday', 'daily_amount'];

    protected $table = 'topup_rules';

    protected $fillable = ['name', 'type', 'coupon_type', 'location_id', 'weekdays', 'max_daily_amount', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return list<int> */
    public function weekdayList(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->weekdays)), fn (int $d) => $d >= 1 && $d <= 7));
    }
}
