<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Dispositivo registrado que hace la operación (cabecera Device-Fingerprint): debe estar habilitado, con
 * localidad activa y licencia vigente, y el usuario debe pertenecer a su localidad. Con
 * `coupons.require_device_binding` es obligatorio; si no, se usa solo cuando viene y es válido.
 */
class DeviceBinding
{
    /** @throws CouponException 403 si falta o no es válido (con el control activo) o si el usuario no es de esa localidad */
    public static function resolve(Request $request, ?User $user = null): ?Device
    {
        $fingerprint = $request->header('Device-Fingerprint');
        $device = is_string($fingerprint) && $fingerprint !== ''
            ? Device::with(['location', 'activeLicense'])->where('device_fingerprint', $fingerprint)->first()
            : null;

        $valid = $device !== null && $device->is_enabled && $device->location?->is_active && $device->activeLicense !== null;

        if (! $valid) {
            if (config('coupons.require_device_binding')) {
                throw new CouponException('Device not authorized', 403);
            }

            return null;
        }

        // El usuario solo opera en las localidades que tiene asignadas (la principal o las de su lista);
        // un ADMIN puede operar en cualquiera.
        if ($user !== null && strtoupper((string) $user->role) !== 'ADMIN' && ! self::userBelongsToLocation($user, (string) $device->location_id)) {
            if (config('coupons.require_device_binding')) {
                SecurityAlerts::raise('user_wrong_location', 'medium', "El usuario {$user->username} intentó operar en una localidad que no tiene asignada.", null, (string) $user->getKey(), (string) $device->location_id);
                throw new CouponException('User is not assigned to the location of this device', 403);
            }
        }

        return $device;
    }

    public static function userBelongsToLocation(User $user, string $locationId): bool
    {
        return (string) $user->location_id === $locationId
            || $user->locations()->where('locations.id', $locationId)->exists();
    }
}
