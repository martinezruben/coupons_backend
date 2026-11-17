<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verificación en dos pasos obligatoria: un administrador que aún no la activó solo puede abrir
 * la pantalla de activación (y salir). Se apaga con ADMIN_MFA_REQUIRED=false.
 */
class EnsureMfaEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (config('admin.mfa_required') && $admin instanceof AdminUser && ! $admin->totp_enabled && ! $request->routeIs('admin.2fa.*', 'admin.logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Activa la verificación en dos pasos para continuar.'], 403);
            }

            return redirect()->route('admin.2fa.show')->with('status', 'Para continuar debes activar la verificación en dos pasos.');
        }

        return $next($request);
    }
}
