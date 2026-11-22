<?php

namespace App\Support;

/**
 * Clave de administración de la app de las tablets (cambiar la URL del API, salir del kiosco). Una sola para todas.
 * Se guarda y se entrega solo como hash `pbkdf2_sha256$<iteraciones>$<sal hex>$<hash hex>`, el mismo formato que
 * verifica la app (PBKDF2-HMAC-SHA256, 32 bytes = 64 caracteres hex, sin conexión). La clave en claro nunca se guarda ni se entrega.
 */
final class AppUnlockKey
{
    public const ALGO = 'pbkdf2_sha256';

    /** La app calcula esto en el teléfono; 100 000 vueltas es un equilibrio entre resistencia y velocidad en tablets modestas. */
    public const ITERATIONS = 100_000;

    public const MIN_LENGTH = 10;

    public static function make(string $plain, ?int $iterations = null, ?string $saltHex = null): string
    {
        $iterations ??= self::ITERATIONS;
        $saltHex ??= bin2hex(random_bytes(16));

        return implode('$', [self::ALGO, $iterations, $saltHex, hash_pbkdf2('sha256', $plain, (string) hex2bin($saltHex), $iterations, 64, false)]);
    }

    public static function verify(string $plain, ?string $encoded): bool
    {
        $p = explode('$', (string) $encoded);
        if ($plain === '' || count($p) !== 4 || $p[0] !== self::ALGO || ! ctype_digit($p[1]) || ! ctype_xdigit($p[2]) || strlen($p[2]) % 2 !== 0) {
            return false;
        }

        return hash_equals($p[3], hash_pbkdf2('sha256', $plain, (string) hex2bin($p[2]), (int) $p[1], 64, false));
    }
}
