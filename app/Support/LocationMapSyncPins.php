<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Location;
use App\Models\SyncLog;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Datos de pins del mapa de localidades: estado operativo en vivo (en línea, sin sync,
 * contingencia, inactiva) y si hubo sync exitoso por ventana temporal.
 */
final class LocationMapSyncPins
{
    public const STATUS_LABELS = [
        'online' => 'En línea',
        'stale' => 'Sin sincronizar',
        'contingency' => 'En contingencia',
        'inactive' => 'Inactiva',
    ];

    /**
     * Pins de las localidades que pasan los mismos filtros que la tabla del grid.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRequest(Request $request, bool $canEdit): array
    {
        $cfg = config('admin_screens.locations');
        $query = Location::query();
        AdminGridQuery::apply($query, $request, 'locations', $cfg);
        $query->withCount(['devices as active_devices_count' => fn ($q) => $q->where('is_enabled', true)]);

        return self::build(
            $query->get(['id', 'name', 'latitude', 'longitude', 'is_active', 'last_sync_at', 'contingency_started_at']),
            $canEdit,
        );
    }

    /**
     * Estado de la localidad. Prioridad: inactiva > contingencia > en línea > sin sincronizar.
     * «En línea» = algún dispositivo habilitado sincronizó dentro de la ventana
     * ({@see DashboardService::STALE_SYNC_HOURS}), el mismo criterio del dashboard técnico.
     */
    public static function statusFor(bool $isActive, bool $inContingency, int $freshDevices): string
    {
        return match (true) {
            ! $isActive => 'inactive',
            $inContingency => 'contingency',
            $freshDevices > 0 => 'online',
            default => 'stale',
        };
    }

    /**
     * @param  Collection<int, Location>  $mapLocations
     * @return list<array<string, mixed>>
     */
    public static function build(Collection $mapLocations, bool $canEdit): array
    {
        $locationIds = $mapLocations->pluck('id');
        $deviceStats = self::deviceStats($locationIds);

        $syncToday = self::locationIdsWithSuccessfulSyncBetween(
            $locationIds,
            now()->startOfDay(),
            now()->copy()->endOfDay(),
        );
        $syncYesterday = self::locationIdsWithSuccessfulSyncBetween(
            $locationIds,
            now()->copy()->subDay()->startOfDay(),
            now()->copy()->subDay()->endOfDay(),
        );
        $syncLastWeek = self::locationIdsWithSuccessfulSyncBetween(
            $locationIds,
            now()->copy()->subDays(6)->startOfDay(),
            now()->copy()->endOfDay(),
        );
        $syncLastMonth = self::locationIdsWithSuccessfulSyncBetween(
            $locationIds,
            now()->copy()->subDays(29)->startOfDay(),
            now()->copy()->endOfDay(),
        );

        return $mapLocations
            ->map(static function (Location $loc) use ($canEdit, $deviceStats, $syncToday, $syncYesterday, $syncLastWeek, $syncLastMonth): array {
                $id = (string) $loc->getKey();
                $enabled = (int) ($deviceStats[$id]['enabled'] ?? 0);
                $fresh = (int) ($deviceStats[$id]['fresh'] ?? 0);
                $status = self::statusFor((bool) $loc->is_active, $loc->contingency_started_at !== null, $fresh);
                $lastSync = collect([$loc->last_sync_at, $deviceStats[$id]['last'] ?? null])->filter()->max();

                return [
                    'id' => $id,
                    'name' => $loc->name,
                    'lat' => $loc->latitude !== null ? (float) $loc->latitude : null,
                    'lng' => $loc->longitude !== null ? (float) $loc->longitude : null,
                    'activeDevices' => (int) ($loc->active_devices_count ?? 0),
                    'isActive' => (bool) $loc->is_active,
                    'status' => $status,
                    'statusLabel' => $status === 'stale' && $enabled === 0 ? 'Sin dispositivos activos' : self::STATUS_LABELS[$status],
                    'enabledDevices' => $enabled,
                    'staleDevices' => $enabled - $fresh,
                    'lastSyncHuman' => $lastSync?->copy()->locale('es')->diffForHumans(),
                    'contingencySinceHuman' => $loc->contingency_started_at?->copy()->locale('es')->diffForHumans(),
                    'editUrl' => $canEdit ? route('admin.screens.edit', ['locations', $loc->getKey()]) : '',
                    'syncInPeriod' => [
                        'today' => $syncToday->contains($id),
                        'yesterday' => $syncYesterday->contains($id),
                        'last_week' => $syncLastWeek->contains($id),
                        'last_month' => $syncLastMonth->contains($id),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Dispositivos habilitados por localidad y cuántos sincronizaron dentro de la ventana.
     *
     * @param  Collection<int, mixed>  $locationIds
     * @return array<string, array{enabled: int, fresh: int, last: ?Carbon}>
     */
    private static function deviceStats(Collection $locationIds): array
    {
        if ($locationIds->isEmpty()) {
            return [];
        }

        $cutoff = now()->subHours(DashboardService::STALE_SYNC_HOURS);

        return Device::query()
            ->where('is_enabled', true)
            ->whereIn('location_id', $locationIds)
            ->selectRaw('location_id, COUNT(*) as enabled, SUM(CASE WHEN last_sync_at >= ? THEN 1 ELSE 0 END) as fresh, MAX(last_sync_at) as last_sync', [$cutoff])
            ->groupBy('location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->location_id => ['enabled' => (int) $row->enabled, 'fresh' => (int) $row->fresh, 'last' => $row->last_sync ? Carbon::parse($row->last_sync) : null]])
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $locationIds
     * @return Collection<int, string>
     */
    private static function locationIdsWithSuccessfulSyncBetween(Collection $locationIds, Carbon $from, Carbon $to): Collection
    {
        if ($locationIds->isEmpty()) {
            return collect();
        }

        return SyncLog::query()
            ->whereIn('location_id', $locationIds)
            ->where('status', 'SUCCESS')
            ->whereBetween('started_at', [$from, $to])
            ->distinct()
            ->pluck('location_id')
            ->map(static fn ($id): string => (string) $id)
            ->values();
    }
}
