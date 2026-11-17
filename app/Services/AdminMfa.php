<?php

namespace App\Services;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/**
 * Comprobación del autenticador (TOTP) de un administrador para aprobar una operación sensible.
 * Un código ya usado no sirve otra vez y los intentos fallidos se limitan por cuenta.
 */
class AdminMfa
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 300;

    public static function tooManyAttempts(AdminUser $admin): bool
    {
        return RateLimiter::tooManyAttempts(self::key($admin), self::MAX_ATTEMPTS);
    }

    public static function secondsUntilRetry(AdminUser $admin): int
    {
        return RateLimiter::availableIn(self::key($admin));
    }

    /** ¿El código TOTP de 6 dígitos es válido y no se usó ya? No lo consume. */
    public static function check(AdminUser $admin, string $code): bool
    {
        $code = trim($code);
        if (! $admin->totp_enabled || ! $admin->totp_secret || ! preg_match('/^\d{6}$/', $code) || Cache::has(self::usedKey($admin, $code))) {
            return false;
        }

        try {
            return (new Google2FA)->verifyKey(Crypt::decryptString($admin->totp_secret), $code, 1);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Marca el código como usado (vale ~2 minutos: más que la ventana de validez del TOTP). */
    public static function consume(AdminUser $admin, string $code): void
    {
        Cache::put(self::usedKey($admin, trim($code)), true, 150);
        RateLimiter::clear(self::key($admin));
    }

    public static function fail(AdminUser $admin): void
    {
        RateLimiter::hit(self::key($admin), self::LOCK_SECONDS);
    }

    private static function key(AdminUser $admin): string
    {
        return 'admin-mfa-approve:'.$admin->getKey();
    }

    private static function usedKey(AdminUser $admin, string $code): string
    {
        return 'admin-mfa-used:'.$admin->getKey().':'.$code;
    }
}
