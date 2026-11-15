<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    protected $fillable = ['name', 'type', 'coupon_type', 'location_id', 'location_ids', 'weekdays', 'time_from', 'time_to', 'max_per_topup', 'max_daily_amount', 'is_active', 'assign_all', 'batch_ids', 'date_field', 'date_from', 'date_to'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'location_ids' => 'array', 'assign_all' => 'boolean', 'batch_ids' => 'array', 'date_from' => 'date', 'date_to' => 'date'];
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

    /** @return list<string> */
    public function assignedNumbers(): array
    {
        return DB::table('topup_rule_coupons')->where('rule_id', $this->getKey())->orderBy('coupon_number')->pluck('coupon_number')->all();
    }

    /** Las reglas anteriores (un solo tipo) valen para todos los cupones, como antes. */
    public function hasAssignment(): bool
    {
        return $this->isLegacy() || $this->assign_all || ! empty($this->batch_ids) || $this->date_from !== null || $this->date_to !== null
            || DB::table('topup_rule_coupons')->where('rule_id', $this->getKey())->exists();
    }

    /**
     * ¿Esta regla está asignada a este cupón? Basta con que se cumpla UNA de sus asignaciones.
     *
     * @param  object{batch_id: ?string, coupon_date: ?string, coupon_expire_date: ?string, coupon_number: string}  $coupon
     */
    public function coversCoupon(object $coupon, bool $numberListed = false): bool
    {
        if ($this->isLegacy() || $this->assign_all) {
            return true;
        }
        if ($numberListed) {
            return true;
        }
        if (! empty($this->batch_ids) && $coupon->batch_id !== null && in_array((string) $coupon->batch_id, array_map('strval', $this->batch_ids), true)) {
            return true;
        }
        if ($this->date_from !== null || $this->date_to !== null) {
            $value = $this->date_field === 'expire' ? $coupon->coupon_expire_date : $coupon->coupon_date;
            if ($value !== null) {
                $day = Carbon::parse($value)->toDateString();
                if (($this->date_from === null || $day >= $this->date_from->toDateString()) && ($this->date_to === null || $day <= $this->date_to->toDateString())) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Resumen en lenguaje natural de a qué cupones se asigna. */
    public function assignmentSummary(): string
    {
        if ($this->isLegacy() || $this->assign_all) {
            return 'Todos los cupones';
        }
        $parts = [];
        if (! empty($this->batch_ids)) {
            $codes = CouponBatch::query()->whereIn('id', $this->batch_ids)->orderBy('code')->pluck('code')->all();
            $parts[] = 'Lotes: '.implode(', ', $codes ?: [count($this->batch_ids).' seleccionados']);
        }
        if ($this->date_from !== null || $this->date_to !== null) {
            $what = $this->date_field === 'expire' ? 'vencen' : 'emitidos';
            $parts[] = "Cupones {$what} ".($this->date_from && $this->date_to ? 'del '.$this->date_from->format('d/m/Y').' al '.$this->date_to->format('d/m/Y') : ($this->date_from ? 'desde el '.$this->date_from->format('d/m/Y') : 'hasta el '.$this->date_to->format('d/m/Y')));
        }
        $n = DB::table('topup_rule_coupons')->where('rule_id', $this->getKey())->count();
        if ($n > 0) {
            $parts[] = $n.' '.($n === 1 ? 'cupón por número' : 'cupones por número');
        }

        return $parts === [] ? 'Sin cupones asignados' : implode(' · ', $parts);
    }
}
