<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Los tokens QR nunca se guardan en claro: el cobro se valida con su hash (SHA-256 con prefijo de versión) y,
 * aparte, se conserva cifrado con APP_KEY (`coupon_token_enc`) solo para volver a dibujar el QR impreso desde el panel
 * (permiso propio y auditoría). En claro existe únicamente en el archivo CSV de la generación del lote.
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

    /** Token cifrado con APP_KEY, para guardarlo junto al hash. */
    public static function seal(string $token): string
    {
        return Crypt::encryptString($token);
    }

    /** Token en claro a partir de su versión cifrada; null si no hay o no se puede descifrar (p. ej. cambió APP_KEY). */
    public static function reveal(?string $sealed): ?string
    {
        if ($sealed === null || $sealed === '') {
            return null;
        }
        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
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
