<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Services\DashboardService;
use App\Support\AdminRbac;
use App\Support\AppVersion;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Dos dashboards: comercial (ventas, `dashboard.view`) y técnico
 * (operación de localidades, dispositivos y sync, `dashboard_technical.view`).
 */
class DashboardController extends Controller
{
    /** Segundos que se reutilizan las cifras de cada dashboard. */
    private const CACHE_SECONDS = 60;

    public function commercial(Request $request): View|RedirectResponse
    {
        // Es la página de inicio tras el login: sin permiso se lleva al técnico
        // o a una bienvenida sin cifras en lugar de un 403.
        if (! $this->canView('dashboard')) {
            return $this->canView('dashboard-technical')
                ? redirect()->route('admin.dashboard.technical')
                : view('admin.dashboard-welcome');
        }

        $location = $this->selectedLocation($request);
        $data = $this->cached('commercial', $location, fn () => $this->commercialData($location));
        // La caché es común a todos los usuarios: las tarjetas con permiso se filtran aquí
        $admin = auth('admin')->user();
        $data['kpis'] = array_values(array_filter(
            $data['kpis'],
            fn (array $card): bool => empty($card['requires']) || $admin?->can($card['requires']),
        ));

        return view('admin.dashboard', $data + [
            'locationOptions' => $this->locationOptions(),
            'selectedLocation' => $location,
        ]);
    }

    public function technical(Request $request): View|RedirectResponse
    {
        if (! $this->canView('dashboard-technical')) {
            return redirect()->route('admin.dashboard');
        }

        $location = $this->selectedLocation($request);
        $data = $this->cached('technical', $location, fn () => $this->technicalData($location));

        return view('admin.dashboard-technical', $data + [
            'locationOptions' => $this->locationOptions(),
            'selectedLocation' => $location,
        ]);
    }

    /**
     * @return array{kpis: list<array<string, mixed>>, chartPayload: array<string, mixed>, topLocations: ?array, topProducts: array}
     */
    private function commercialData(?Location $location): array
    {
        $service = new DashboardService($location?->id);

        $k = $service->getKpis();
        $voided = $service->getVoidedTransactions();
        $margin = $service->getMarginSummary(30);

        $kpis = [
            [
                'label' => 'Ventas hoy',
                'value' => Format::money($k['salesToday']),
                'icon' => 'banknotes',
                'featured' => true,
            ],
            [
                'label' => 'Transacciones hoy',
                'value' => (string) $k['txToday'],
                'icon' => 'receipt-percent',
            ],
            [
                'label' => 'Ticket promedio hoy',
                'value' => $k['txToday'] > 0 ? Format::money($k['avgTicketToday']) : '—',
                'sub' => $this->deltaLabel($k['avgTicketToday'], $k['avgTicketYesterday']),
                'icon' => 'calculator',
            ],
            [
                'label' => 'Ventas semana vs. anterior',
                'value' => $k['weekDeltaPct'] === null ? '—' : ($k['weekDeltaPct'] >= 0 ? '+' : '').Format::percent($k['weekDeltaPct']),
                'sub' => Format::money($k['salesThisWeek']).' vs '.Format::money($k['salesLastWeek']),
                'icon' => $k['weekDeltaPct'] !== null && $k['weekDeltaPct'] < 0 ? 'arrow-trending-down' : 'arrow-trending-up',
                'tone' => $k['weekDeltaPct'] === null ? null : ($k['weekDeltaPct'] < 0 ? 'bad' : 'good'),
            ],
            [
                'label' => 'Ventas (7 días)',
                'value' => Format::money($k['sales7d']),
                'icon' => 'calendar-days',
            ],
            [
                'label' => 'Ventas (30 días)',
                'value' => Format::money($k['sales30d']),
                'icon' => 'chart-bar-square',
            ],
            [
                'label' => 'Anulaciones hoy',
                'value' => (string) $voided['today']['count'],
                'sub' => Format::money($voided['today']['total']),
                'icon' => 'no-symbol',
                'tone' => $voided['today']['count'] > 0 ? 'warn' : null,
            ],
            [
                'label' => 'Anulaciones (7 días)',
                'value' => (string) $voided['week']['count'],
                'sub' => Format::money($voided['week']['total']),
                'icon' => 'arrow-uturn-left',
            ],
            [
                'label' => 'Margen (30 días)',
                'value' => $margin['margin'] === null ? '—' : Format::money($margin['margin']),
                'sub' => $margin['margin'] === null
                    ? 'Sin costos cargados'
                    : Format::percent($margin['margin_pct'] ?? 0).' · costo en '.Format::percent($margin['coverage_pct'] ?? 0, 0).' de las ventas',
                'icon' => 'scale',
                // El costo y el margen son sensibles: solo con el permiso del reporte de margen
                'requires' => 'products_margin.view',
            ],
        ];

        $chartPayload = [
            'salesTrend' => $service->getDailySalesTrend(now()->subDays(29)->startOfDay(), now()->endOfDay()),
            'heatmap' => ['30d' => $service->getSalesHeatmap(30), '90d' => $service->getSalesHeatmap(90)],
            'familyMix' => $service->getSalesByFamily(30),
            'paymentMix' => $service->getSalesByPaymentMethod(30),
        ];

        return [
            'kpis' => $kpis,
            'chartPayload' => $chartPayload,
            // Con una localidad elegida el ranking de localidades no aporta
            'topLocations' => $location ? null : $service->getTopLocationsBySales(30, 5),
            'topProducts' => $service->getTopProductsBySales(30, 5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalData(?Location $location): array
    {
        $service = new DashboardService($location?->id);

        $contingencies = $service->getLocationsInContingency();
        $devicesNoSync = $service->getDevicesNotSyncedSince();
        $licensesExpiring = $service->getLicensesExpiringWithin(7);
        $minAppVersion = AppVersion::normalize(SystemParameter::query()->value('min_app_version'));
        $outdatedDevices = $service->getOutdatedDevices();
        $usersNoPin = $service->getUsersWithoutOfflinePin();
        $sync7d = $service->getSyncStats7d();
        $syncFailures24h = $service->getSyncFailuresLast24h();
        $counts = $service->getInventoryCounts();

        $kpis = [
            [
                'label' => 'Localidades activas',
                'value' => (string) $counts['locations'],
                'icon' => 'building-storefront',
            ],
            [
                'label' => 'En contingencia',
                'value' => (string) count($contingencies),
                'sub' => count($contingencies) > 0 ? 'Ver localidades' : 'Ninguna',
                'icon' => 'shield-exclamation',
                'tone' => count($contingencies) > 0 ? 'warn' : 'good',
                'modal' => count($contingencies) > 0 ? 'dash-contingencies' : null,
            ],
            [
                'label' => 'Dispositivos habilitados',
                'value' => (string) $counts['devices'],
                'icon' => 'device-phone-mobile',
            ],
            [
                'label' => 'Sin sincronizar (+4 h)',
                'value' => (string) count($devicesNoSync),
                'sub' => count($devicesNoSync) > 0 ? 'Ver dispositivos' : 'Todos al día',
                'icon' => 'cloud-arrow-up',
                'tone' => count($devicesNoSync) > 0 ? 'warn' : 'good',
                'modal' => count($devicesNoSync) > 0 ? 'dash-devices-no-sync' : null,
            ],
            [
                'label' => 'Licencias activas',
                'value' => (string) $counts['licenses'],
                'icon' => 'key',
            ],
            [
                'label' => 'Licencias por vencer (7 días)',
                'value' => (string) count($licensesExpiring),
                'sub' => count($licensesExpiring) > 0 ? 'Ver licencias' : 'Ninguna',
                'icon' => 'clock',
                'tone' => count($licensesExpiring) > 0 ? 'warn' : 'good',
                'modal' => count($licensesExpiring) > 0 ? 'dash-licenses-expiring' : null,
            ],
            ...($minAppVersion === null ? [] : [[
                'label' => 'App desactualizada (< '.$minAppVersion.')',
                'value' => (string) count($outdatedDevices),
                'sub' => count($outdatedDevices) > 0 ? 'Ver dispositivos' : 'Todos al día',
                'icon' => 'arrow-up-circle',
                'tone' => count($outdatedDevices) > 0 ? 'warn' : 'good',
                'modal' => count($outdatedDevices) > 0 ? 'dash-outdated-devices' : null,
            ]]),
            [
                'label' => 'Usuarios sin PIN offline',
                'value' => (string) $usersNoPin['count'],
                'sub' => $usersNoPin['count'] > 0 ? 'Ver usuarios' : 'Todos al día',
                'icon' => 'finger-print',
                'tone' => $usersNoPin['count'] > 0 ? 'warn' : 'good',
                'modal' => $usersNoPin['count'] > 0 ? 'dash-users-no-pin' : null,
            ],
            [
                'label' => 'Sync correctas (7 días)',
                'value' => $sync7d['okPct'] === null ? '—' : $sync7d['okPct'].'%',
                'sub' => $sync7d['success'].' de '.($sync7d['success'] + $sync7d['failed']),
                'icon' => 'check-badge',
                'tone' => $sync7d['okPct'] === null ? null : ($sync7d['okPct'] >= 95 ? 'good' : 'warn'),
            ],
            [
                'label' => 'Sync fallidas (24 h)',
                'value' => (string) $syncFailures24h['count'],
                'sub' => 'de '.$syncFailures24h['total'].' sincronizaciones',
                'icon' => 'exclamation-circle',
                'tone' => $syncFailures24h['count'] > 0 ? 'bad' : 'good',
            ],
        ];

        return [
            'kpis' => $kpis,
            'contingencies' => $contingencies,
            'devicesNoSync' => $devicesNoSync,
            'licensesExpiring' => $licensesExpiring,
            'outdatedDevices' => $outdatedDevices,
            'usersNoPin' => $usersNoPin,
            'minAppVersion' => $minAppVersion,
            'activity' => $service->getRecentActivity(8),
            'chartPayload' => [
                'syncByDay' => $service->getSyncSuccessFailedByDay(now()->subDays(13)->startOfDay(), now()->endOfDay()),
            ],
        ];
    }

    /**
     * Cifras del dashboard en caché por dashboard y localidad; `generatedAt`
     * indica en pantalla a qué hora se calcularon.
     *
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function cached(string $dashboard, ?Location $location, callable $build): array
    {
        $key = 'dashboard:'.$dashboard.':'.($location?->id ?? 'all');

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $build() + ['generatedAt' => now()->format('H:i')]);
    }

    /** Localidad del filtro `?location_id=`; un ID desconocido se ignora. */
    private function selectedLocation(Request $request): ?Location
    {
        $id = $request->query('location_id');

        return is_string($id) && $id !== '' ? Location::query()->find($id) : null;
    }

    /**
     * @return Collection<int, Location>
     */
    private function locationOptions()
    {
        return Location::query()->orderBy('name')->get(['id', 'name', 'is_active']);
    }

    private function canView(string $screen): bool
    {
        return (bool) auth('admin')->user()?->can(AdminRbac::permissionsForScreen($screen)['view']);
    }

    /** Etiqueta de variación % vs. el día anterior. */
    private function deltaLabel(float $current, float $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? 'vs. ayer: nuevo' : 'vs. ayer: —';
        }
        $pct = round(100 * ($current - $previous) / $previous, 1);

        return 'vs. ayer: '.($pct >= 0 ? '+' : '').$pct.'%';
    }
}
