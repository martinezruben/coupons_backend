<?php

use App\Http\Middleware\EnsureAdminActive;
use App\Http\Middleware\EnsureDeviceOperational;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Purga de logs operativos (sync, api, auditoría)
        $schedule->command('pos:prune-logs')->dailyAt('03:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return route('admin.login');
        });
        $middleware->alias([
            'device.operational' => EnsureDeviceOperational::class,
            'admin.active' => EnsureAdminActive::class,
        ]);

        $middleware->appendToGroup('api', LogApiRequest::class);

        // Detrás de un proxy con TLS (p. ej. Caddy del perfil "https"): confiar solo en
        // los proxies de TRUSTED_PROXIES. Sin configurar no se confía en ninguno, para
        // que un cliente no pueda falsear su IP (límites de intentos) con X-Forwarded-For.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Autenticación requerida: falta el token del dispositivo o no es válido. Incluye la cabecera Authorization: Bearer <token> (token obtenido con POST /api/auth/login).',
                'error' => 'unauthenticated',
            ], 401);
        });
    })->create();
