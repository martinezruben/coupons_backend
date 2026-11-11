<?php

namespace App\Providers;

use App\Events\NcfRangeLow;
use App\Listeners\SendNcfRangeLowNotification;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Shift;
use App\Models\Subfamily;
use App\Models\SystemParameter;
use App\Models\User;
use App\Observers\AuditsModelChanges;
use App\Observers\LocationContingencyObserver;
use App\Services\NcfService;
use App\Support\AdminRbac;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(NcfService::class, function ($app) {
            return new NcfService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        // NCF: notificar admin cuando rango esté bajo
        Event::listen(NcfRangeLow::class, SendNcfRangeLowNotification::class);

        // Contingencia: notificaciones cuando localidad entra/sale de contingencia
        Location::observe(LocationContingencyObserver::class);

        // Auditoría del panel: cambios en modelos clave + login/logout del admin
        AuditsModelChanges::track([
            Product::class,
            Promotion::class,
            Family::class,
            Subfamily::class,
            Location::class,
            Device::class,
            License::class,
            Shift::class,
            User::class,
            AdminUser::class,
            SystemParameter::class,
            NcfSequence::class,
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
}
