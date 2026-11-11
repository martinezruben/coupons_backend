<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * RBAC del panel: cada pantalla CRUD usa permisos `{recurso}.view`, `.edit`, `.delete`
 * donde `recurso` es la clave de pantalla con guiones sustituidos por guiones bajos (p. ej. `transaction_items`).
 */
class AdminRbac
{
    /**
     * ¿Es super-admin (tiene alguno de los roles de `config('admin.super_admin_roles')`)?
     */
    public static function isSuperAdmin(?Authenticatable $user): bool
    {
        if (! $user || ! method_exists($user, 'hasRole')) {
            return false;
        }

        foreach (config('admin.super_admin_roles', []) as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pantalla reservada a super-admin (`'super_admin_only' => true`): no genera permisos asignables,
     * no aparece en la matriz RBAC y solo la ven/usan los super-admin (usuarios administradores, roles).
     */
    public static function isSuperAdminOnly(string $screen): bool
    {
        return ! empty(config("admin_screens.$screen.super_admin_only"));
    }

    public static function resourceKey(string $screen): string
    {
        return str_replace('-', '_', $screen);
    }

    /**
     * @return array{view: string, edit: string, delete: string}
     */
    public static function permissionsForScreen(string $screen): array
    {
        $r = self::resourceKey($screen);

        return [
            'view' => $r.'.view',
            'edit' => $r.'.edit',
            'delete' => $r.'.delete',
        ];
    }

    /**
     * Pantalla sujeta a RBAC: tiene modelo CRUD o se marca `'rbac' => true`
     * (pantallas sin modelo como el dashboard, que solo exponen `.view`).
     */
    private static function isManaged(mixed $cfg): bool
    {
        if (is_array($cfg) && ! empty($cfg['super_admin_only'])) {
            return false;
        }

        return is_array($cfg) && (! empty($cfg['model']) || ! empty($cfg['rbac']));
    }

    /**
     * Todos los nombres de permiso CRUD definidos por las pantallas en `admin_screens`.
     *
     * @return list<string>
     */
    public static function allCrudPermissionNames(): array
    {
        $names = [];
        foreach (array_keys(config('admin_screens', [])) as $screen) {
            $cfg = config("admin_screens.$screen");
            if (! self::isManaged($cfg)) {
                continue;
            }
            $p = self::permissionsForScreen($screen);
            $names[] = $p['view'];
            if (! empty($cfg['model'])) {
                $names[] = $p['edit'];
                $names[] = $p['delete'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Pantallas sujetas a RBAC, ordenadas por etiqueta, para la matriz RBAC.
     *
     * @return list<array{key: string, label: string, readonly: bool}>
     */
    public static function managedScreens(): array
    {
        $out = [];
        foreach (array_keys(config('admin_screens', [])) as $screen) {
            $cfg = config("admin_screens.$screen");
            if (! self::isManaged($cfg)) {
                continue;
            }
            $out[] = [
                'key' => $screen,
                'label' => $cfg['label'] ?? $screen,
                'readonly' => ! empty($cfg['readonly']),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Permisos asignables en la matriz por pantalla (solo view si la pantalla es solo lectura).
     *
     * @return list<string>
     */
    public static function managedPermissionNamesForScreen(string $screen): array
    {
        $cfg = config("admin_screens.$screen");
        if (! self::isManaged($cfg)) {
            return [];
        }
        $p = self::permissionsForScreen($screen);
        if (! empty($cfg['readonly']) || empty($cfg['model'])) {
            return [$p['view']];
        }

        return [$p['view'], $p['edit'], $p['delete']];
    }

    /**
     * Todos los permisos que la matriz RBAC puede activar o quitar en un rol.
     *
     * @return list<string>
     */
    public static function allManagedPermissionNames(): array
    {
        $names = [];
        foreach (self::managedScreens() as $s) {
            $names = array_merge($names, self::managedPermissionNamesForScreen($s['key']));
        }

        return array_values(array_unique($names));
    }

    public static function canAccessScreen(?Authenticatable $user, string $screen): bool
    {
        if (! $user) {
            return false;
        }
        if (self::isSuperAdminOnly($screen)) {
            return self::isSuperAdmin($user);
        }
        $cfg = config("admin_screens.$screen");
        if (! self::isManaged($cfg)) {
            return true;
        }
        $p = self::permissionsForScreen($screen);

        return $user->can($p['view']);
    }
}
