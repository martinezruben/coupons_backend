<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Http\Request;

/**
 * Versión de la app Android por dispositivo: la app la envía en la cabecera `App-Version`
 * (p. ej. `1.4.2`); el panel la muestra y marca los equipos por debajo de la mínima configurada.
 */
final class AppVersion
{
    public const HEADER = 'App-Version';

    /** Se vuelve a anotar «visto por última vez» como mucho cada tantas horas si no cambió la versión. */
    private const REFRESH_HOURS = 6;

    /** Acepta `1`, `1.4`, `1.4.2`, `1.4.2.7` y un sufijo opcional (`-debug`, `+build5`). */
    public static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $v = trim($raw);

        return $v !== '' && strlen($v) <= 30 && preg_match('/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.\-]+)?$/', $v) === 1 ? $v : null;
    }

    /** Verdadero solo si ambas versiones existen y `$version` es menor que `$minimum`. */
    public static function isOutdated(?string $version, ?string $minimum): bool
    {
        $version = self::normalize($version);
        $minimum = self::normalize($minimum);
        if ($version === null || $minimum === null) {
            return false;
        }

        return version_compare(self::base($version), self::base($minimum), '<');
    }

    /** Guarda la versión que reporta el dispositivo (sin tocar `updated_at` ni la auditoría). */
    public static function record(Device $device, Request $request): void
    {
        $version = self::normalize($request->header(self::HEADER));
        if ($version === null) {
            return;
        }

        $stale = $device->app_version_seen_at === null
            || $device->app_version_seen_at->lt(now()->subHours(self::REFRESH_HOURS));

        if ($version === $device->app_version && ! $stale) {
            return;
        }

        Device::query()->whereKey($device->getKey())->update([
            'app_version' => $version,
            'app_version_seen_at' => now(),
        ]);
        $device->forceFill(['app_version' => $version, 'app_version_seen_at' => now()])->syncOriginal();
    }

    private static function base(string $version): string
    {
        return preg_replace('/[-+].*$/', '', $version);
    }
}
