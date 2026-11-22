<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros de cupones editables en el panel (Parámetros del sistema → Cupones). Los valores guardados en
 * `system_settings` pisan a `config/coupons.php` (que sigue siendo el valor por defecto, vía .env), así que el
 * resto del código sigue leyendo `config('coupons.…')`.
 */
final class CouponSettings
{
    private const CACHE_KEY = 'coupon_settings';

    /**
     * clave de config => [grupo, tipo (bool|int|decimal|string), etiqueta, ayuda]
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'require_idempotency_key' => ['Cobro y dispositivos', 'bool', 'Exigir Idempotency-Key en cobros y recargas', 'Evita que un reintento por mala red cobre dos veces. La app actual ya la envía.'],
            'require_device_binding' => ['Cobro y dispositivos', 'bool', 'Exigir dispositivo registrado (Device-Fingerprint)', 'Los cobros y recargas solo se aceptan desde un dispositivo habilitado, con licencia y localidad activas, y de un usuario de esa localidad.'],
            'require_device_token' => ['Cobro y dispositivos', 'bool', 'Exigir el token privado del terminal (vínculo real)', 'La app envía Device-Token y las sesiones quedan atadas a su terminal: la huella copiada o un token de usuario robado ya no sirven desde otro equipo. Activa solo cuando todas las tablets tengan la app actualizada (usa la versión mínima).'],
            'require_dispenser' => ['Cobro y dispositivos', 'bool', 'Exigir el surtidor en cada cobro', 'Aplica a localidades con surtidores (> 0). Activa solo cuando la app ya envíe `dispenser`.'],
            'enforce_min_app_version' => ['Cobro y dispositivos', 'bool', 'Bloquear apps por debajo de la versión mínima (426)', 'La versión mínima se fija arriba, en Parámetros del sistema. Sin versión mínima no se bloquea nada.'],
            'require_qr_token' => ['Cobro y dispositivos', 'bool', 'Exigir el token del cupón QR en cada cobro', 'Recomendado: déjalo activo.'],
            'token_ttl_minutes' => ['Cobro y dispositivos', 'int', 'Vida de la sesión de la app (minutos)', '720 = 12 horas. Aplica a los inicios de sesión nuevos.'],
            'login_max_attempts' => ['Cobro y dispositivos', 'int', 'Intentos de login fallidos antes de bloquear al usuario de la app', '0 = sin bloqueo. Se cuentan por cuenta; un login correcto los reinicia.'],
            'login_lockout_minutes' => ['Cobro y dispositivos', 'int', 'Minutos de bloqueo de la cuenta', 'El administrador puede desbloquearla antes desde Usuarios de la app.'],
            'idempotency_ttl_hours' => ['Cobro y dispositivos', 'int', 'Vida de la clave de idempotencia (horas)', 'Tiempo durante el cual un reintento devuelve la respuesta original.'],
            'max_coupons_per_transaction' => ['Montos y límites', 'int', 'Máximo de cupones por cobro', ''],
            'max_amount' => ['Montos y límites', 'decimal', 'Monto máximo por cobro o recarga', ''],
            'max_balance' => ['Montos y límites', 'decimal', 'Saldo máximo de un cupón tras una recarga', ''],
            'limits.per_coupon_daily_count' => ['Montos y límites', 'int', 'Cobros máximos por cupón al día', '0 = sin límite.'],
            'limits.per_user_daily_amount' => ['Montos y límites', 'decimal', 'Monto máximo por usuario al día', '0 = sin límite.'],
            'limits.per_location_daily_amount' => ['Montos y límites', 'decimal', 'Monto máximo por localidad al día', '0 = sin límite.'],
            'limits.large_redeem_threshold' => ['Montos y límites', 'decimal', 'Monto desde el cual el cobro exige supervisor', '0 = no se exige. Los cobros mayores requieren un MANAGER o ADMIN.'],
            'self_reverse_allowed' => ['Reversas y ajustes', 'bool', 'Permitir revertir un cobro propio', 'Apagado: quien hizo el cobro no puede revertirlo.'],
            'reverse_window_hours' => ['Reversas y ajustes', 'int', 'Ventana para revertir (horas)', 'Pasado este plazo solo un ADMIN revierte. 0 = sin plazo.'],
            'restore_requires_approval' => ['Reversas y ajustes', 'bool', 'Los ajustes de saldo requieren aprobación', 'Otro administrador aprueba en Cupones → Ajustes de saldo.'],
            'replace_requires_approval' => ['Reversas y ajustes', 'bool', 'El reemplazo de un cupón perdido requiere aprobación', 'Otro administrador aprueba en Cupones → Reemplazos de cupón. Apagado, se aplica al pedirlo.'],
            'separation_of_duties' => ['Reversas y ajustes', 'bool', 'Quien genera un lote no lo asigna ni lo activa', ''],
            'export_ttl_hours' => ['Reversas y ajustes', 'int', 'Horas de vida del archivo CSV de un lote', 'Se borra tras su primera descarga o al pasar este plazo.'],
            'alerts.enabled' => ['Alertas', 'bool', 'Alertas de seguridad activas', ''],
            'alerts.business_hours' => ['Alertas', 'string', 'Horario normal de cobros (HH-HH)', 'Ej. 7-22. Fuera de él se alerta. Vacío = no se evalúa.'],
            'alerts.multi_location_minutes' => ['Alertas', 'int', 'Minutos para detectar un cupón usado en dos localidades', ''],
            'alerts.failed_lookups' => ['Alertas', 'int', 'Consultas fallidas seguidas que disparan alerta', 'En 10 minutos. 0 = no se evalúa.'],
            'alerts.reversals_per_hour' => ['Alertas', 'int', 'Reversas por hora que disparan alerta', '0 = no se evalúa.'],
        ];
    }

    /** Reemplaza en `config('coupons')` los valores guardados. Se llama al arrancar la app. */
    public static function apply(): void
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('system_settings')
                ? DB::table('system_settings')->where('key', 'like', 'coupons.%')->pluck('value', 'key')->all() : []);
        } catch (\Throwable) {
            return;
        }

        foreach (self::definitions() as $key => [, $type]) {
            if (array_key_exists("coupons.$key", $stored)) {
                config(["coupons.$key" => self::cast($stored["coupons.$key"], $type)]);
            }
        }
    }

    /** Valor vigente de cada parámetro (guardado o, si no, el de config/.env). */
    public static function current(): array
    {
        return collect(self::definitions())->mapWithKeys(fn ($d, $key) => [$key => config("coupons.$key")])->all();
    }

    /** @param  array<string, mixed>  $values clave => valor ya validado */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => "coupons.$key"], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** Reglas de validación según el tipo de cada parámetro. */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::definitions() as $key => [, $type]) {
            $rules['coupon.'.self::field($key)] = match ($type) {
                'bool' => ['nullable', 'boolean'],
                'int' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
                'decimal' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
                default => $key === 'alerts.business_hours' ? ['nullable', 'regex:/^\d{1,2}-\d{1,2}$/'] : ['nullable', 'string', 'max:100'],
            };
        }

        return $rules;
    }

    /** Nombre del campo del formulario: los puntos de la clave se cambian por «__» (un punto en el nombre sería un anidado). */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => in_array((string) $value, ['1', 'true'], true),
            'int' => (int) $value,
            'decimal' => number_format((float) $value, 2, '.', ''),
            default => (string) $value,
        };
    }
}
