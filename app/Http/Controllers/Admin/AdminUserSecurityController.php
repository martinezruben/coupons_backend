<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminActive;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Acciones de seguridad sobre un usuario administrador (solo super-admin):
 * reiniciar su verificación en dos pasos y cerrar sus sesiones abiertas.
 */
class AdminUserSecurityController extends Controller
{
    public function resetTwoFactor(Request $request, AdminUser $adminUser): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);

        if ($actor->is($adminUser)) {
            return back()->withErrors(['security' => 'Tu propia verificación en dos pasos se gestiona desde tu perfil (pide un código).']);
        }
        if (! $adminUser->totp_enabled) {
            return back()->withErrors(['security' => 'Este usuario no tiene la verificación en dos pasos activada.']);
        }

        $adminUser->forceFill([
            'totp_secret' => null,
            'totp_enabled' => false,
            'totp_recovery_codes' => null,
            'sessions_revoked_at' => now(),
        ])->save();

        AdminAuditLog::record('two_factor_reset', 'AdminUser', (string) $adminUser->getKey(), ['totp_enabled' => [true, false]], $actor);

        return back()->with('status', 'Verificación en dos pasos reiniciada y sesiones cerradas. El usuario puede volver a activarla desde su perfil.');
    }

    public function revokeSessions(Request $request, AdminUser $adminUser): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);

        $adminUser->forceFill(['sessions_revoked_at' => now()])->save();

        if ($actor->is($adminUser)) {
            // Cierra las demás sesiones propias, no la actual
            $request->session()->put(EnsureAdminActive::SESSION_KEY, now()->timestamp + 1);
        }

        AdminAuditLog::record('sessions_revoked', 'AdminUser', (string) $adminUser->getKey(), null, $actor);

        return back()->with('status', $actor->is($adminUser)
            ? 'Se cerraron tus otras sesiones.'
            : 'Se cerraron todas las sesiones de '.$adminUser->name.'.');
    }

    private function authorizeSuperAdmin(Request $request): AdminUser
    {
        $actor = $request->user('admin');
        abort_unless($actor && AdminRbac::isSuperAdmin($actor), 403);

        return $actor;
    }
}
