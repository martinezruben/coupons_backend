<?php

namespace App\Http\Middleware;

use App\Models\SystemParameter;
use App\Support\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 426 Upgrade Required: con una versión mínima configurada (Parámetros del sistema), la app que envíe una
 * versión menor, o ninguna, no puede operar hasta actualizarse. Sin versión mínima no bloquea nada.
 */
class EnforceMinAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('coupons.enforce_min_app_version')) {
            return $next($request);
        }

        $minimum = AppVersion::normalize(SystemParameter::query()->value('min_app_version'));
        if ($minimum === null) {
            return $next($request);
        }

        $sent = AppVersion::normalize($request->header(AppVersion::HEADER));
        if ($sent === null || AppVersion::isOutdated($sent, $minimum)) {
            return response()->json([
                'error' => 'App update required',
                'code' => 'app_update_required',
                'min_app_version' => $minimum,
                'app_version' => $sent,
            ], 426);
        }

        return $next($request);
    }
}
