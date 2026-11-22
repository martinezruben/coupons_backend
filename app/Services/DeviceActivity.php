<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Location;
use App\Support\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Actividad real de los terminales: cada petición de cupones de un terminal registrado marca su «última actividad»
 * (y la de su localidad) y anota la versión de la app; cada cobro marca su «último cobro». Son actualizaciones directas
 * (sin eventos del modelo) y la actividad se anota como mucho una vez por minuto por terminal.
 */
class DeviceActivity
{
    private const SEEN_SECONDS = 60;

    public static function seen(?Device $device, Request $request): void
    {
        if ($device === null) {
            return;
        }

        try {
            AppVersion::record($device, $request);

            if (Cache::add('device-seen:'.$device->getKey(), 1, self::SEEN_SECONDS)) {
                Device::query()->whereKey($device->getKey())->update(['last_sync_at' => now()]);
                if ($device->location_id) {
                    Location::query()->whereKey($device->location_id)->update(['last_sync_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            report($e); // la telemetría nunca tumba una operación de cupones
        }
    }

    public static function transacted(?Device $device): void
    {
        if ($device === null) {
            return;
        }

        try {
            Device::query()->whereKey($device->getKey())->update(['last_transaction_at' => now(), 'last_sync_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
