<?php

use App\Http\Middleware\EnforceMinAppVersion;
use App\Http\Middleware\EnsureAdminActive;
use App\Http\Middleware\EnsureCouponDevice;
use App\Http\Middleware\EnsureCouponUser;
use App\Http\Middleware\EnsureDeviceOperational;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // Cupones: activaciones programadas cada minuto y borrado de archivos CSV vencidos
        $schedule->command('coupons:activate-due')->everyMinute()->withoutOverlapping();
        $schedule->command('coupons:purge-exports')->hourly();
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
            'coupon.user' => EnsureCouponUser::class,
            'coupon.device' => EnsureCouponDevice::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'idempotent' => EnsureIdempotency::class,
            'app.version' => EnforceMinAppVersion::class,
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
                'code' => 'unauthenticated',
            ], 401);
        });

        // Errores de la API con la misma forma que los de negocio: `error` (texto) y `code` (estable)
        $exceptions->renderable(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => (string) collect($e->errors())->flatten()->first(),
                'code' => 'validation_failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->renderable(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $retry = (int) ($e->getHeaders()['Retry-After'] ?? 0);

            return response()->json([
                'error' => 'Too many requests',
                'code' => 'rate_limited',
                'message' => 'Too Many Attempts.',
                'retry_after' => $retry,
            ], 429, $e->getHeaders());
        });

        $exceptions->renderable(function (AuthorizationException|AccessDeniedHttpException|MissingAbilityException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['error' => 'Forbidden', 'code' => 'forbidden', 'message' => 'This action is not allowed for your account.'], 403);
        });

        $exceptions->renderable(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['error' => 'Not found', 'code' => 'not_found', 'message' => 'Resource not found.'], 404);
        });
    })->create();
