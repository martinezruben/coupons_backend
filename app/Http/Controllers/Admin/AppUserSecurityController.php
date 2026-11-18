<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Seguridad de la cuenta de un usuario de la app (POS): cerrar sus sesiones en las tablets y desbloquearlo. */
class AppUserSecurityController extends Controller
{
    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        $actor = $this->authorizeEdit($request);
        $count = $user->tokens()->count();
        $user->tokens()->delete();
        AdminAuditLog::record('sessions_revoked', 'User', (string) $user->getKey(), ['sesiones' => [$count, 0]], $actor);

        return back()->with('status', "Se cerraron las sesiones de {$user->username} ({$count}).");
    }

    public function unlock(Request $request, User $user): RedirectResponse
    {
        $actor = $this->authorizeEdit($request);
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        AdminAuditLog::record('unlocked', 'User', (string) $user->getKey(), null, $actor);

        return back()->with('status', "Cuenta de {$user->username} desbloqueada.");
    }

    private function authorizeEdit(Request $request)
    {
        $actor = $request->user('admin');
        abort_unless($actor && $actor->can(AdminRbac::permissionsForScreen('android-users')['edit']), 403);

        return $actor;
    }
}
