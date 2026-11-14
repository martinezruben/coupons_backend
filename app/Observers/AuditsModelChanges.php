<?php

namespace App\Observers;

use App\Models\ActivationOrder;
use App\Models\AdminAuditLog;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\CouponTransaction;
use App\Support\AuditLabels;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra created/updated/deleted de los modelos clave en admin_audit_logs, siempre con el dato
 * antes y después ({campo: [antes, después]}). Sin datos sensibles.
 */
class AuditsModelChanges
{
    public const SENSITIVE = ['password', 'pin_sha384', 'pin4', 'pin4_enc', 'remember_token', 'coupon_token', 'totp_secret', 'totp_recovery_codes'];

    /** Cambios operacionales que generan ruido (se actualizan por sync, no por un admin). */
    public const NOISY = ['last_sync_at'];

    private const IGNORED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** Modelos que la API o los jobs también tocan: solo se auditan si quien cambia es un administrador. */
    private const ADMIN_ONLY = [
        CouponBatch::class, Coupon::class, BarcodeCoupon::class,
        ActivationOrder::class, CouponTransaction::class,
    ];

    public static function track(array $classes): void
    {
        foreach ($classes as $class) {
            $class::observe(static::class);
        }
    }

    public function created(Model $model): void
    {
        $this->write('created', $model, $this->snapshot($model->getAttributes(), fn ($v) => [null, $v]));
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, self::SENSITIVE, true) || in_array($field, self::NOISY, true) || in_array($field, self::IGNORED, true)) {
                continue;
            }
            $changes[$field] = [$this->plain($model->getOriginal($field)), $this->plain($new)];
        }
        if ($changes === []) {
            return;
        }
        $this->write('updated', $model, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write('deleted', $model, $this->snapshot($model->getAttributes(), fn ($v) => [$v, null]));
    }

    private function write(string $action, Model $model, array $changes): void
    {
        if (in_array($model::class, self::ADMIN_ONLY, true) && ! auth('admin')->check()) {
            return;
        }

        $entity = class_basename($model);
        $name = $this->label($model);

        AdminAuditLog::record($action, $entity, (string) $model->getKey(), $changes === [] ? null : $changes, extra: [
            'summary' => AuditLabels::describeChange($action, $entity).($name !== null ? " «{$name}»" : ''),
        ]);
    }

    /** @param  callable(mixed): array{0: mixed, 1: mixed}  $pair */
    private function snapshot(array $attributes, callable $pair): array
    {
        $out = [];
        foreach ($attributes as $field => $value) {
            if (in_array($field, self::SENSITIVE, true) || in_array($field, self::IGNORED, true) || $value === null || $value === '') {
                continue;
            }
            $out[$field] = $pair($this->plain($value));
        }

        return $out;
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500).'…';
        }

        return $value;
    }

    private function label(Model $model): ?string
    {
        foreach (['name', 'code', 'prefix', 'username', 'email', 'coupon_number', 'full_name'] as $attribute) {
            $value = $model->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 80);
            }
        }

        return null;
    }
}
