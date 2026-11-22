<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DeviceBinding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Valida el terminal (huella + token del terminal + sesión atada) en todas las rutas de cupones y lo deja en la petición. */
class EnsureCouponDevice
{
    public const ATTRIBUTE = 'coupon_device';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $request->attributes->set(self::ATTRIBUTE, DeviceBinding::resolve($request, $user instanceof User ? $user : null, checkSession: true));

        return $next($request);
    }
}
