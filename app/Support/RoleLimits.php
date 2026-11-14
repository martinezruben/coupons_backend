<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\RoleLimit;

/**
 * Límites por rol (tamaño de activaciones, cantidad y valor de lotes). Si un administrador tiene varios
 * roles se aplica el más permisivo; un rol sin límite propio (o sin fila) no limita y vale solo el
 * máximo del sistema. El super-admin no tiene límites propios.
 */
final class RoleLimits
{
    public const KEYS = ['max_activation_size', 'max_batch_quantity', 'max_batch_value'];

    public static function for(AdminUser $admin, string $key): int|float|null
    {
        if (! in_array($key, self::KEYS, true) || $admin->isSuperAdmin()) {
            return null;
        }

        $roleIds = $admin->roles()->pluck('roles.id');
        if ($roleIds->isEmpty()) {
            return null;
        }

        $rows = RoleLimit::query()->whereIn('role_id', $roleIds)->pluck($key, 'role_id');
        $values = $roleIds->map(fn ($id) => $rows[$id] ?? null);

        // Un solo rol sin límite propio basta para no limitar
        if ($values->contains(null)) {
            return null;
        }

        return $key === 'max_batch_value' ? (float) $values->max() : (int) $values->max();
    }
}
