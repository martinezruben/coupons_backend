<?php

namespace App\Support;

/**
 * Formato único de cifras del panel (convención de República Dominicana):
 * miles con coma y decimales con punto, p. ej. $13,095.50 y 39.8 %.
 * Los gráficos usan el mismo formato vía `resources/js/format.js`.
 */
class Format
{
    public static function money(float|int|string|null $value): string
    {
        $value = (float) $value;

        return ($value < 0 ? '-$' : '$').number_format(abs($value), 2);
    }

    /** Cantidades: sin decimales si es entero, hasta 2 si no (1.5, 3). */
    public static function number(float|int|string|null $value): string
    {
        $value = (float) $value;

        if (floor($value) == $value) {
            return number_format($value);
        }

        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    public static function percent(float|int|string|null $value, int $decimals = 1): string
    {
        return number_format((float) $value, $decimals).'%';
    }

    /** Fecha y hora local: 28/09/2026 14:05 (con segundos para logs). */
    public static function dateTime(?\DateTimeInterface $value, bool $seconds = false): string
    {
        return $value === null ? '—' : $value->format($seconds ? 'd/m/Y H:i:s' : 'd/m/Y H:i');
    }
}
