<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la sesión del panel cuando el administrador ya no debe tener acceso:
 *  - la cuenta fue desactivada (`is_active` = false), o
 *  - alguien cerró sus sesiones (`sessions_revoked_at` posterior al inicio de esta sesión).
 */
class EnsureAdminActive
{
    public const SESSION_KEY = 'admin_session_started_at';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof AdminUser) {
            $startedAt = (int) $request->session()->get(self::SESSION_KEY, 0);
            if ($startedAt === 0) {
                // Sesión anterior a este control: se toma como iniciada ahora
                $request->session()->put(self::SESSION_KEY, $startedAt = now()->timestamp);
            }

            $revoked = ! $admin->is_active
                || ($admin->sessions_revoked_at !== null && $admin->sessions_revoked_at->timestamp >= $startedAt);

            if ($revoked) {
                auth('admin')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.login')->withErrors([
                    'email' => $admin->is_active
                        ? 'Tu sesión fue cerrada por un administrador. Inicia sesión de nuevo.'
                        : 'Tu cuenta está desactivada. Contacta a un administrador.',
                ]);
            }
        }

        return $next($request);
    }

    /** Marca el inicio de una sesión de panel (login normal y tras el desafío 2FA). */
    public static function markSessionStart(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->timestamp);
    }
}
