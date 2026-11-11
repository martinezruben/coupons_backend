<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Código de 6 dígitos por localidad (panel admin → Localidades) para vincular un terminal a esa sede.
 */
final class DevicePairingToken
{
    public const TTL_MINUTES = 15;

    private const CACHE_PREFIX_LOC = 'admin_device_pairing_v2:loc:';

    private const CACHE_PREFIX_CODE = 'admin_device_pairing_v2:code:';

    private static function locationKey(string $locationId): string
    {
        return self::CACHE_PREFIX_LOC.$locationId;
    }

    private static function codeKey(string $sixDigitCode): string
    {
        return self::CACHE_PREFIX_CODE.$sixDigitCode;
    }

    /**
     * @return array{code: string, expires_at: int, location_id: string}|null
     */
    public static function getPayloadForLocation(string $locationId): ?array
    {
        $payload = Cache::get(self::locationKey($locationId));
        if (! is_array($payload) || empty($payload['code']) || empty($payload['expires_at'])) {
            return null;
        }
        if ((int) $payload['expires_at'] < now()->getTimestamp()) {
            return null;
        }

        return $payload;
    }

    /**
     * Valida el código, lo invalida (un solo uso) y devuelve el UUID de la localidad.
     */
    public static function validateAndConsume(string $input): ?string
    {
        $normalized = preg_replace('/\D/', '', $input);
        if (strlen($normalized) !== 6) {
            return null;
        }
        $entry = Cache::get(self::codeKey($normalized));
        if (! is_array($entry) || empty($entry['location_id'])) {
            return null;
        }
        if ((int) ($entry['expires_at'] ?? 0) < now()->getTimestamp()) {
            Cache::forget(self::codeKey($normalized));

            return null;
        }
        $locationId = (string) $entry['location_id'];
        self::forgetLocationKeys($locationId);
        Cache::forget(self::codeKey($normalized));

        return $locationId;
    }

    /**
     * @return array{code: string, expires_at: int, location_id: string}
     */
    public static function generateNew(string $locationId): array
    {
        self::forgetLocationKeys($locationId);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        $ts = $expiresAt->getTimestamp();
        $payload = [
            'code' => $code,
            'expires_at' => $ts,
            'location_id' => $locationId,
        ];
        Cache::put(self::locationKey($locationId), $payload, $expiresAt);
        Cache::put(self::codeKey($code), [
            'location_id' => $locationId,
            'expires_at' => $ts,
        ], $expiresAt);

        return $payload;
    }

    private static function forgetLocationKeys(string $locationId): void
    {
        $old = Cache::get(self::locationKey($locationId));
        if (is_array($old) && ! empty($old['code'])) {
            Cache::forget(self::codeKey((string) $old['code']));
        }
        Cache::forget(self::locationKey($locationId));
    }
}
