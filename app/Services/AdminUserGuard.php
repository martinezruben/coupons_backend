<?php

namespace App\Services;

use App\Models\AdminUser;
use Illuminate\Validation\ValidationException;

/**
 * Reglas que protegen el acceso al panel al cambiar usuarios administradores:
 *  - nadie se desactiva ni se quita el rol super-admin a sí mismo;
 *  - el sistema nunca se queda sin un super-admin activo.
 */
final class AdminUserGuard
{
    /**
     * @param  list<string>  $newRoles  Nombres de rol que tendrá el usuario tras el cambio
     *
     * @throws ValidationException
     */
    public static function assertChangeAllowed(AdminUser $actor, AdminUser $target, array $newRoles, bool $newActive): void
    {
        $superRoles = config('admin.super_admin_roles', []);
        $staysSuper = array_intersect($superRoles, $newRoles) !== [];
        $wasActiveSuper = $target->is_active && $target->isSuperAdmin();

        if ($actor->is($target)) {
            if (! $newActive) {
                throw ValidationException::withMessages(['is_active' => 'No puedes desactivar tu propia cuenta.']);
            }
            if ($target->isSuperAdmin() && ! $staysSuper) {
                throw ValidationException::withMessages(['role_names' => 'No puedes quitarte a ti mismo el rol de super-admin.']);
            }
        }

        if ($wasActiveSuper && (! $newActive || ! $staysSuper) && ! self::otherActiveSuperAdminExists($target)) {
            throw ValidationException::withMessages([
                $newActive ? 'role_names' : 'is_active' => 'No se puede dejar el sistema sin un super-admin activo.',
            ]);
        }
    }

    public static function otherActiveSuperAdminExists(AdminUser $except): bool
    {
        return AdminUser::query()
            ->role(config('admin.super_admin_roles', []), 'admin')
            ->where('is_active', true)
            ->whereKeyNot($except->getKey())
            ->exists();
    }
}
