<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\CouponTransaction;
use App\Models\SecurityAlert;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Alertas de seguridad de cupones: se guardan en `coupon_security_alerts` (pantalla del panel) y las de
 * severidad alta se envían por correo a los super-administradores. Nunca rompen la operación que las genera.
 */
class SecurityAlerts
{
    public static function raise(string $type, string $severity, string $message, ?string $couponNumber = null, ?string $userId = null, ?string $locationId = null, array $details = []): ?SecurityAlert
    {
        if (! config('coupons.alerts.enabled')) {
            return null;
        }

        try {
            // La misma alerta (tipo + cupón + usuario) no se repite durante 10 minutos
            if (! Cache::add("secalert:$type:$couponNumber:$userId", 1, 600)) {
                return null;
            }
            $alert = SecurityAlert::create([
                'type' => $type, 'severity' => $severity, 'message' => mb_substr($message, 0, 255),
                'coupon_number' => $couponNumber, 'user_id' => $userId, 'location_id' => $locationId,
                'details' => $details ?: null, 'created_at' => now(),
            ]);
            if (in_array($severity, (array) config('coupons.alerts.email_severities'), true)) {
                self::email($alert);
            }

            return $alert;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function email(SecurityAlert $alert): void
    {
        $roles = (array) config('admin.super_admin_roles', []);
        $to = AdminUser::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))->pluck('email')->filter()->all();
        if ($to === []) {
            return;
        }
        $body = "Alerta de seguridad ({$alert->severity}): {$alert->message}\nTipo: {$alert->type}\nCupón: ".($alert->coupon_number ?? '—')."\nFecha: {$alert->created_at}\nRevísala en el panel: Cupones → Alertas de seguridad.";
        Mail::raw($body, fn ($m) => $m->to($to)->subject('Coupify — alerta de seguridad: '.$alert->type));
    }

    /** Después de un cobro: cupón usado en dos localidades seguidas y cobros fuera de horario. */
    public static function afterRedeem(array $numbers, ?string $locationId, ?string $userId, string $amount): void
    {
        $minutes = (int) config('coupons.alerts.multi_location_minutes');
        if ($locationId && $minutes > 0) {
            foreach ($numbers as $number) {
                $other = CouponTransaction::query()->where('coupon_number', $number)->where('kind', CouponTransaction::KIND_REDEEM)
                    ->where('created_at', '>=', now()->subMinutes($minutes))->whereNotNull('location_id')->where('location_id', '!=', $locationId)->exists();
                if ($other) {
                    self::raise('multi_location', 'high', "El cupón {$number} se cobró en dos localidades distintas en menos de {$minutes} minutos.", $number, $userId, $locationId);
                }
            }
        }

        if (self::outsideBusinessHours()) {
            self::raise('off_hours', 'medium', "Cobro de {$amount} fuera del horario normal (".config('coupons.alerts.business_hours').').', null, $userId, $locationId, ['numbers' => $numbers]);
        }
    }

    /** Muchas reversas del mismo usuario en poco tiempo. */
    public static function afterReverse(Authenticatable $actor): void
    {
        $limit = (int) config('coupons.alerts.reversals_per_hour');
        if ($limit <= 0) {
            return;
        }
        $col = $actor instanceof AdminUser ? 'admin_user_id' : 'user_id';
        $count = CouponTransaction::query()->where('kind', CouponTransaction::KIND_REVERSAL)->where($col, $actor->getKey())->where('created_at', '>=', now()->subHour())->count();
        if ($count >= $limit) {
            self::raise('many_reversals', 'high', "{$count} reversas en la última hora de la misma cuenta.", null, $actor instanceof AdminUser ? null : (string) $actor->getKey(), null, ['actor' => $actor->getKey(), 'admin' => $actor instanceof AdminUser]);
        }
    }

    /** Consultas fallidas seguidas (posible enumeración de cupones). */
    public static function failedLookup(?string $userId, ?string $ip): void
    {
        $limit = (int) config('coupons.alerts.failed_lookups');
        if ($limit <= 0) {
            return;
        }
        $key = 'lookup-fail:'.($userId ?: $ip);
        Cache::add($key, 0, 600);
        if ((int) Cache::increment($key) >= $limit) {
            self::raise('failed_lookups', 'high', "{$limit} consultas de cupón fallidas en 10 minutos (posible enumeración).", null, $userId, null, ['ip' => $ip]);
        }
    }

    private static function outsideBusinessHours(): bool
    {
        if (! preg_match('/^(\d{1,2})-(\d{1,2})$/', (string) config('coupons.alerts.business_hours'), $m)) {
            return false;
        }
        [$from, $to] = [(int) $m[1], (int) $m[2]];
        $h = (int) now()->format('G');

        return $from <= $to ? ($h < $from || $h >= $to) : ($h < $from && $h >= $to);
    }
}
