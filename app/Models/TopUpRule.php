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

    public const MODERN = 'rule';

    private const DAY_NAMES = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    protected $fillable = ['name', 'type', 'coupon_type', 'location_id', 'location_ids', 'weekdays', 'time_from', 'time_to', 'max_per_topup', 'max_daily_amount', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'location_ids' => 'array'];
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

    /** Las reglas de un solo tipo (antes de poder sumar condiciones) siguen funcionando como antes. */
    public function isLegacy(): bool
    {
        return $this->type !== self::MODERN;
    }

    /** @return list<string> */
    public function locationIdList(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', array_merge((array) $this->location_ids, $this->location_id ? [$this->location_id] : [])))));
    }

    /**
     * Las condiciones de la regla en lenguaje natural (todas deben cumplirse).
     *
     * @return list<string>
     */
    public function conditionsSummary(): array
    {
        $out = [];
        if ($ids = $this->locationIdList()) {
            $names = Location::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
            $out[] = 'Localidades: '.($names ? implode(', ', $names) : count($ids).' seleccionadas');
        }
        if ($days = $this->weekdayList()) {
            $out[] = 'Días: '.implode(', ', array_map(fn (int $d) => self::DAY_NAMES[$d], $days));
        }
        if ($this->time_from && $this->time_to) {
            $out[] = "Horario: {$this->time_from} a {$this->time_to}";
        }
        if ($this->max_per_topup !== null) {
            $out[] = 'Máx. por recarga: $'.number_format((float) $this->max_per_topup, 2);
        }
        if ($this->max_daily_amount !== null) {
            $out[] = 'Máx. por día y cupón: $'.number_format((float) $this->max_daily_amount, 2);
        }

        return $out;
    }
}
