<?php

namespace App\Providers;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Models\User;
use App\Observers\AuditsModelChanges;
use App\Observers\LocationContingencyObserver;
use App\Support\AdminRbac;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureCouponRateLimiting();

        Paginator::defaultView('vendor.pagination.tailwind');
        Paginator::defaultSimpleView('vendor.pagination.simple-tailwind');

        // Con APP_URL en https, todos los enlaces y redirecciones salen en https
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Pulse muestra consultas lentas, errores y correos de usuarios: solo con
        // el permiso pulse.view (asignable en la matriz RBAC) o super-admin
        Gate::define('viewPulse', function (?Authenticatable $user = null) {
            return $user instanceof AdminUser
                && $user->is_active
                && $user->can(AdminRbac::permissionsForScreen('pulse')['view']);
        });

        Pulse::user(function (Authenticatable $user) {
            if ($user instanceof AdminUser) {
                return [
                    'name' => $user->name,
                    'extra' => $user->email ?? '',
                    'avatar' => null,
                ];
            }

            return [
                'name' => $user->name ?? '—',
                'extra' => $user->email ?? '',
                'avatar' => null,
            ];
        });

        Livewire::setUpdateRoute(function ($handle) {
            return Route::post(EndpointResolver::updatePath(), $handle)
                ->middleware(['web', 'auth:admin']);
        });

        // Contingencia: notificaciones cuando localidad entra/sale de contingencia
        Location::observe(LocationContingencyObserver::class);

        // Auditoría del panel: cambios en modelos clave + login/logout del admin
        AuditsModelChanges::track([
            Location::class,
            Device::class,
            License::class,
            User::class,
            AdminUser::class,
            SystemParameter::class,
        ]);
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof AdminUser) {
                AdminAuditLog::record('login', 'AdminUser', (string) $event->user->getKey(), admin: $event->user);
            }
        });
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof AdminUser) {
                AdminAuditLog::record('logout', 'AdminUser', (string) $event->user->getKey(), admin: $event->user);
            }
        });

        Gate::before(function ($user, ?string $ability = null) {
            if (! $user instanceof AdminUser || $ability === null) {
                return null;
            }

            foreach (config('admin.super_admin_roles', []) as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }

            return null;
        });
    }

    /** Límites de la API de cupones: contra fuerza bruta y enumeración de cupones. */
    private function configureCouponRateLimiting(): void
    {
        $who = fn (Request $r) => $r->user()?->getKey() ?: $r->ip();

        RateLimiter::for('coupon-login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) ($r->input('username') ?? $r->input('email'))).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        RateLimiter::for('coupon-api', fn (Request $r) => Limit::perMinute(120)->by($who($r)));
        RateLimiter::for('coupon-lookup', fn (Request $r) => [
            Limit::perMinute(30)->by($who($r)),
            Limit::perMinute(60)->by($r->ip()),
        ]);
        RateLimiter::for('coupon-redeem', fn (Request $r) => Limit::perMinute(30)->by($who($r)));
        RateLimiter::for('coupon-restricted', fn (Request $r) => Limit::perMinute(10)->by($who($r)));
    }
}
