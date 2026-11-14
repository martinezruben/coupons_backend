<?php

namespace App\Support;

/**
 * Los tokens QR nunca se guardan en claro: solo su hash (SHA-256 con prefijo de versión).
 * El token en claro existe únicamente en el archivo CSV de la generación del lote.
 * Los cupones heredados con token en claro siguen validando hasta que se les aplique
 * `coupons:hash-tokens`.
 */
final class CouponToken
{
    private const PREFIX = 'h1:';

    public static function generate(): string
    {
        return bin2hex(random_bytes(16)); // 128 bits
    }

    public static function hash(string $token): string
    {
        return self::PREFIX.hash('sha256', $token);
    }

    public static function isHashed(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PREFIX);
    }

    public static function matches(?string $stored, ?string $given): bool
    {
        if ($stored === null || $stored === '' || $given === null || $given === '') {
            return false;
        }

        return hash_equals($stored, self::isHashed($stored) ? self::hash($given) : $given);
    }
}
