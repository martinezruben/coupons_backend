<?php

namespace App\Observers;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra created/updated/deleted del panel en admin_audit_logs.
 * Solo atributos modificados (old => new); sin datos sensibles.
 */
class AuditsModelChanges
{
    public const SENSITIVE = ['password', 'pin_sha384', 'remember_token'];

    /** Cambios operacionales que generan ruido (se actualizan por sync, no por un admin). */
    public const NOISY = ['last_sync_at'];

    public static function track(array $classes): void
    {
        foreach ($classes as $class) {
            $class::observe(static::class);
        }
    }

    public function created(Model $model): void
    {
        AdminAuditLog::record('created', class_basename($model), (string) $model->getKey());
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, self::SENSITIVE, true) || in_array($field, self::NOISY, true) || $field === 'updated_at') {
                continue;
            }
            $changes[$field] = [$model->getOriginal($field), $new];
        }
        if ($changes === []) {
            return;
        }
        AdminAuditLog::record('updated', class_basename($model), (string) $model->getKey(), $changes);
    }

    public function deleted(Model $model): void
    {
        AdminAuditLog::record('deleted', class_basename($model), (string) $model->getKey());
    }
}
