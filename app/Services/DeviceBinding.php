<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Vínculo real con el terminal. El `Device-Fingerprint` solo identifica (cualquiera puede copiarlo); lo que
 * prueba que la petición sale del terminal registrado es el `Device-Token`: el token Sanctum privado que el
 * servidor entregó a ese terminal al emparejarlo (o al renovarlo con su licencia) y que nunca sale de su
 * almacenamiento cifrado. Además, la sesión del usuario queda atada al terminal donde inició sesión
 * (habilidad `device:<id>` en su token), así que un token de usuario robado no sirve desde otro equipo.
 *
 * Reglas:
 *  - Con `Device-Token`: debe ser válido, de un terminal, y coincidir con el `Device-Fingerprint`. Siempre.
 *  - Sin `Device-Token`: se rechaza si `coupons.require_device_token`; si no, se usa la huella (modo de transición).
 *  - Con `coupons.require_device_binding`: el terminal debe estar habilitado, con localidad activa y licencia.
 *  - Con `coupons.require_device_token`: el token del usuario debe pertenecer a este terminal.
 *  - El usuario debe pertenecer a la localidad del terminal (un ADMIN opera en cualquiera).
 */
class DeviceBinding
{
    public const HEADER_TOKEN = 'Device-Token';

    /**
     * @param  bool  $checkSession  verifica que el token del usuario esté atado a este terminal (no aplica al login)
     *
     * @throws CouponException 403 con `code` (device_not_authorized, device_token_invalid, device_token_required,
     *                         session_device_mismatch, user_wrong_location)
     */
    public static function resolve(Request $request, ?User $user = null, bool $checkSession = false): ?Device
    {
        $fingerprint = $request->header('Device-Fingerprint');
        $fingerprint = is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;
        $plain = $request->header(self::HEADER_TOKEN);
        $plain = is_string($plain) && $plain !== '' ? $plain : null;

        $viaToken = false;
        if ($plain !== null) {
            $device = self::deviceFromToken($plain);
            if ($device === null || $fingerprint === null || ! hash_equals((string) $device->device_fingerprint, $fingerprint)) {
                self::tokenAlert($fingerprint, $user);
                throw new CouponException('Device token is not valid for this device', 403, [], 'device_token_invalid');
            }
            $device->loadMissing(['location', 'activeLicense']);
            $viaToken = true;
        } else {
            if (config('coupons.require_device_token')) {
                throw new CouponException('Device token required', 403, [], 'device_token_required');
            }
            $device = $fingerprint !== null
                ? Device::with(['location', 'activeLicense'])->where('device_fingerprint', $fingerprint)->first()
                : null;
        }

        $valid = $device !== null && $device->is_enabled && $device->location?->is_active && $device->activeLicense !== null;
        if (! $valid) {
            if (config('coupons.require_device_binding') || $viaToken) {
                throw new CouponException('Device not authorized', 403);
            }

            return null;
        }

        // La sesión del usuario debe haberse iniciado en este mismo terminal
        if ($checkSession && config('coupons.require_device_token') && $user !== null) {
            $token = $user->currentAccessToken();
            if ($token === null || ! method_exists($token, 'can') || ! $token->can(self::sessionAbility($device))) {
                throw new CouponException('This session was not started on this device', 403, [], 'session_device_mismatch');
            }
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

    /** Habilidad que ata el token del usuario a un terminal. */
    public static function sessionAbility(Device $device): string
    {
        return 'device:'.$device->getKey();
    }

    public static function userBelongsToLocation(User $user, string $locationId): bool
    {
        return (string) $user->location_id === $locationId
            || $user->locations()->where('locations.id', $locationId)->exists();
    }

    /** Terminal dueño del token (no vencido, de un Device); null si no existe o es de otro tipo. */
    private static function deviceFromToken(string $plain): ?Device
    {
        $token = PersonalAccessToken::findToken($plain);
        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }
        $owner = $token->tokenable;
        if (! $owner instanceof Device) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $owner;
    }

    /** Huella de un terminal conocido con un token que no le corresponde: posible clonación o robo de huella. */
    private static function tokenAlert(?string $fingerprint, ?User $user): void
    {
        $known = $fingerprint !== null ? Device::query()->where('device_fingerprint', $fingerprint)->first() : null;
        if ($known !== null) {
            SecurityAlerts::raise('device_token_invalid', 'high', "Petición con la huella del terminal {$fingerprint} y un token que no le corresponde (posible clonación).", null, $user?->getKey() ? (string) $user->getKey() : null, $known->location_id ? (string) $known->location_id : null);
        }
    }
}
