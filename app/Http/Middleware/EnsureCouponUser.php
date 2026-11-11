<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas de cupones solo aceptan tokens de usuarios de la app (no de dispositivos) y solo
 * mientras el usuario siga activo: desactivar un usuario corta su acceso de inmediato.
 */
class EnsureCouponUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
