<?php

namespace App\Support;

use App\Models\SystemParameter;
use Illuminate\Validation\ValidationException;

class PasswordPolicy
{
    /**
     * Reglas base: longitud mínima (sin complejidad; se valida aparte).
     *
     * @return list<string>
     */
    public static function baseRules(string $kind): array
    {
        $p = SystemParameter::query()->first();
        $prefix = $kind === 'admin' ? 'admin_' : 'pos_';
        $defaultMin = $kind === 'admin' ? 8 : 4;
        $min = $p !== null ? max(1, (int) $p->{$prefix.'password_min_length'}) : $defaultMin;

        return ['string', 'min:'.$min];
    }

    /**
     * @throws ValidationException
     */
    public static function assertComplexity(string $plain, string $kind): void
    {
        $p = SystemParameter::query()->first();
        if ($p === null) {
            return;
        }

        $prefix = $kind === 'admin' ? 'admin_' : 'pos_';
        $label = $kind === 'admin' ? 'usuarios backend' : 'usuarios POS';

        $parts = [];
        if ($p->{$prefix.'password_require_uppercase'} && ! preg_match('/[A-Z]/', $plain)) {
            $parts[] = 'al menos una letra mayúscula';
        }
        if ($p->{$prefix.'password_require_lowercase'} && ! preg_match('/[a-z]/', $plain)) {
            $parts[] = 'al menos una letra minúscula';
        }
        if ($p->{$prefix.'password_require_digit'} && ! preg_match('/[0-9]/', $plain)) {
            $parts[] = 'al menos un dígito';
        }
        if ($p->{$prefix.'password_require_symbol'} && ! preg_match('/[^A-Za-z0-9]/', $plain)) {
            $parts[] = 'al menos un carácter especial';
        }

        if ($parts === []) {
            return;
        }

        $msg = 'La contraseña ('.$label.') debe incluir '.implode(', ', $parts).'.';

        throw ValidationException::withMessages(['password' => [$msg]]);
    }
}
