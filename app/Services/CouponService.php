<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\Device;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Logica de cupones (QR y codigo de barras). Todas las operaciones que mueven saldo
 * se ejecutan en una transaccion de BD con bloqueo de filas (lockForUpdate), validan
 * activo/vencimiento y usan aritmetica decimal exacta (nunca float).
 */
class CouponService
{
    public const TYPE_QR = 'QRCODE';

    public const TYPE_BARCODE = 'CODBAR';

    /** @param class-string<Model> $model */
    private function __construct(private string $model, private string $type) {}

    public static function qr(): self
    {
        return new self(Coupon::class, self::TYPE_QR);
    }

    public static function barcode(): self
    {
        return new self(BarcodeCoupon::class, self::TYPE_BARCODE);
    }

    public static function forType(string $type): self
    {
        return $type === self::TYPE_QR ? self::qr() : self::barcode();
    }

    /** @return array{active: bool, number: string, date: string, expire: string, amount: string, balance: string} */
    public function balance(string $number, ?string $token = null): array
    {
        $coupon = $this->model::where('coupon_number', $number)->first();

        // Mismo error si no existe o si el token no coincide: no se revela cual fallo.
        if (! $coupon || ($this->type === self::TYPE_QR && ! $this->tokenMatches($coupon, $token))) {
            throw new CouponException('Coupon not found', 404);
        }

        return [
            'active' => $this->isUsable($coupon),
            'number' => $coupon->coupon_number,
            'date' => $this->ymd($coupon->coupon_date),
            'expire' => $this->ymd($coupon->coupon_expire_date),
            'amount' => $this->money($coupon->coupon_amount),
            'balance' => $this->money($coupon->coupon_balance),
        ];
    }

    /**
     * @param  list<string>  $numbers
     * @param  list<string|null>|null  $tokens  paralelo a $numbers (solo QR)
     */
    public function redeem(array $numbers, ?array $tokens, string $amount, ?Device $device, ?User $user, ?string $ip): array
    {
        $required = BigDecimal::of($amount)->toScale(2, self::rounding());

        return DB::transaction(function () use ($numbers, $tokens, $required, $device, $user, $ip) {
            // Orden fijo por numero => sin deadlocks entre cobros concurrentes.
            $rows = $this->model::whereIn('coupon_number', $numbers)
                ->orderBy('coupon_number')
                ->lockForUpdate()
                ->get()
                ->keyBy('coupon_number');

            $sum = BigDecimal::zero();
            foreach ($numbers as $i => $number) {
                $coupon = $rows->get($number);
                if (! $coupon) {
                    throw new CouponException('Coupon not found', 404);
                }
                if ($this->type === self::TYPE_QR && config('coupons.require_qr_token')
                    && ! $this->tokenMatches($coupon, $tokens[$i] ?? null)) {
                    // Mismo mensaje que "no existe" para no permitir enumerar cupones.
                    throw new CouponException('Coupon not found', 404);
                }
                if (! $this->isUsable($coupon)) {
                    throw new CouponException('Coupon is inactive or expired', 422, ['coupon_number' => $number]);
                }
                $sum = $sum->plus($this->decimal($coupon->coupon_balance));
            }

            if ($sum->isLessThan($required)) {
                throw new CouponException('Insufficient balance', 400, [
                    'balance' => $this->money($sum),
                    'amount_required' => $this->money($required),
                ]);
            }

            $remaining = $required;
            $result = [];
            foreach ($numbers as $number) {
                $coupon = $rows->get($number);
                $initial = $this->decimal($coupon->coupon_balance);
                $used = $remaining->isLessThan($initial) ? $remaining : $initial;
                $newBalance = $initial->minus($used);
                $remaining = $remaining->minus($used);

                if ($used->isPositive()) {
                    $this->model::where('coupon_id', $coupon->coupon_id)
                        ->update(['coupon_balance' => $this->money($newBalance)]);

                    CouponTransaction::create([
                        'coupon_id' => $coupon->coupon_id,
                        'coupon_number' => $coupon->coupon_number,
                        'transaction_amount' => $this->money($used),
                        'coupon_type' => $this->type,
                        'kind' => CouponTransaction::KIND_REDEEM,
                        'user_id' => $user?->getKey(),
                        'device_id' => $device?->getKey(),
                        'location_id' => $device?->location_id,
                        'ip_address' => $ip,
                    ]);
                }

                $result[] = [
                    'coupon_number' => $coupon->coupon_number,
                    'balance_initial' => $this->money($initial),
                    'balance' => $this->money($newBalance),
                    'transaction_amount' => $this->money($used),
                ];
            }

            return [
                'coupons' => $result,
                'sumbalance' => $this->money($sum),
                'sumtransaction_amount' => $this->money($required),
            ];
        });
    }

    /** Solo admin (ver ruta): fija el saldo, nunca por encima del monto original del cupon. */
    public function restore(string $number, string $amount, string $reason, Authenticatable $user, ?string $ip): array
    {
        $newBalance = BigDecimal::of($amount)->toScale(2, self::rounding());

        return DB::transaction(function () use ($number, $newBalance, $reason, $user, $ip) {
            $coupon = $this->model::where('coupon_number', $number)->lockForUpdate()->first();
            if (! $coupon) {
                throw new CouponException('Coupon not found', 404);
            }
            if ($newBalance->isGreaterThan($this->decimal($coupon->coupon_amount))) {
                throw new CouponException('Balance cannot exceed the coupon amount', 422, [
                    'coupon_amount' => $this->money($coupon->coupon_amount),
                ]);
            }

            $old = $this->money($coupon->coupon_balance);
            $this->model::where('coupon_id', $coupon->coupon_id)
                ->update(['coupon_balance' => $this->money($newBalance)]);

            $this->audit('restore', $this->type, $number, $old, $this->money($newBalance), $reason, $user, $ip);

            return ['number' => $number, 'balance' => $this->money($newBalance)];
        });
    }

    /** Reversa de una transaccion: devuelve el monto y deja una fila compensatoria. */
    public static function reverse(CouponTransaction $original, string $reason, Authenticatable $user, ?string $ip): array
    {
        $service = self::forType($original->coupon_type);

        return DB::transaction(function () use ($service, $original, $reason, $user, $ip) {
            // Bloquea la fila original para que dos reversas simultaneas no pasen a la vez.
            $tx = CouponTransaction::where('transaction_id', $original->transaction_id)->lockForUpdate()->first();

            if ($tx->kind !== CouponTransaction::KIND_REDEEM || $tx->reversed_of !== null || BigDecimal::of((string) $tx->transaction_amount)->isNegativeOrZero()) {
                throw new CouponException('This transaction cannot be reversed', 422);
            }
            if (CouponTransaction::where('reversed_of', $tx->transaction_id)->exists()) {
                throw new CouponException('Transaction already reversed', 409);
            }

            $coupon = $service->model::where('coupon_id', $tx->coupon_id)->lockForUpdate()->first();
            if (! $coupon) {
                throw new CouponException('Coupon not found', 404);
            }

            $new = $service->decimal($coupon->coupon_balance)->plus($service->decimal($tx->transaction_amount));
            $service->model::where('coupon_id', $coupon->coupon_id)
                ->update(['coupon_balance' => $service->money($new)]);

            $compensation = CouponTransaction::create([
                'coupon_id' => $tx->coupon_id,
                'coupon_number' => $tx->coupon_number,
                'transaction_amount' => $service->money($service->decimal($tx->transaction_amount)->negated()),
                'device_id' => $tx->device_id,
                'location_id' => $tx->location_id,
                'legacy_device_id' => $tx->legacy_device_id,
                'legacy_site_id' => $tx->legacy_site_id,
                'coupon_type' => $tx->coupon_type,
                ...self::actor($user),
                'ip_address' => $ip,
                'reversed_of' => $tx->transaction_id,
                'kind' => CouponTransaction::KIND_REVERSAL,
            ]);

            $service->audit('reverse', $tx->coupon_type, $tx->coupon_number, $service->money($coupon->coupon_balance),
                $service->money($new), $reason, $user, $ip);

            return [
                'reversal_transaction_id' => $compensation->transaction_id,
                'coupon_number' => $tx->coupon_number,
                'balance' => $service->money($new),
            ];
        });
    }

    private function audit(string $action, string $type, string $number, string $old, string $new, string $reason, Authenticatable $user, ?string $ip): void
    {
        CouponAuditLog::create([
            ...self::actor($user),
            'action' => $action,
            'coupon_type' => $type,
            'coupon_number' => $number,
            'old_value' => $old,
            'new_value' => $new,
            'reason' => $reason,
            'ip_address' => $ip,
        ]);
    }

    /**
     * Recarga (TOP-UP): suma saldo y monto al cupón, respetando las reglas de recarga activas y el
     * saldo máximo. En la API (QR) se exige el token; en el panel el administrador no lo necesita.
     */
    public function topUp(string $number, ?string $token, string $amount, ?Device $device, Authenticatable $actor, ?string $ip, bool $verifyToken = true, ?string $note = null): array
    {
        $amountDec = BigDecimal::of($amount)->toScale(2, self::rounding());

        return DB::transaction(function () use ($number, $token, $amountDec, $device, $actor, $ip, $verifyToken, $note) {
            $coupon = $this->model::where('coupon_number', $number)->lockForUpdate()->first();

            if (! $coupon || ($verifyToken && $this->type === self::TYPE_QR && ! $this->tokenMatches($coupon, $token))) {
                throw new CouponException('Coupon not found', 404);
            }
            if (! $this->isUsable($coupon)) {
                throw new CouponException('Coupon is inactive or expired', 422, ['coupon_number' => $number]);
            }

            TopUpRules::assertAllowed($this->type, $amountDec, $number, $device?->location_id, $device !== null || $verifyToken);

            $old = $this->decimal($coupon->coupon_balance);
            $new = $old->plus($amountDec);
            if ($new->isGreaterThan(BigDecimal::of((string) config('coupons.max_balance')))) {
                throw new CouponException('Balance limit exceeded', 422, ['max_balance' => $this->money(config('coupons.max_balance'))]);
            }

            $this->model::where('coupon_id', $coupon->coupon_id)->update([
                'coupon_balance' => $this->money($new),
                'coupon_amount' => $this->money($this->decimal($coupon->coupon_amount)->plus($amountDec)),
            ]);

            $tx = CouponTransaction::create([
                'coupon_id' => $coupon->coupon_id,
                'coupon_number' => $coupon->coupon_number,
                'transaction_amount' => $this->money($amountDec),
                'coupon_type' => $this->type,
                'kind' => CouponTransaction::KIND_TOPUP,
                ...self::actor($actor),
                'device_id' => $device?->getKey(),
                'location_id' => $device?->location_id,
                'ip_address' => $ip,
            ]);

            $this->audit('topup', $this->type, $number, $this->money($old), $this->money($new), 'Recarga de '.$this->money($amountDec).($note ? ': '.$note : ''), $actor, $ip);

            return [
                'number' => $number,
                'topup_amount' => $this->money($amountDec),
                'balance' => $this->money($new),
                'transaction_id' => $tx->transaction_id,
            ];
        });
    }

    /** Quién actúa: usuario de la app (UUID) o administrador del panel (ID entero). */
    private static function actor(?Authenticatable $actor): array
    {
        return [
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'admin_user_id' => $actor instanceof AdminUser ? $actor->getKey() : null,
        ];
    }

    /**
     * Emite cupones nuevos (panel). Números aleatorios no adivinables; los QR llevan además un token
     * secreto que solo se devuelve aquí (nunca se vuelve a mostrar).
     *
     * @return list<array{coupon_number: string, coupon_token: ?string, qr_payload: ?string}>
     */
    public function issue(int $quantity, string $amount, ?string $expireDate, string $prefix, Authenticatable $actor, ?string $ip): array
    {
        $amountDec = BigDecimal::of($amount)->toScale(2, self::rounding());
        $isQr = $this->type === self::TYPE_QR;

        return DB::transaction(function () use ($quantity, $amountDec, $expireDate, $prefix, $actor, $ip, $isQr) {
            $issued = [];
            for ($i = 0; $i < $quantity; $i++) {
                do {
                    $number = $prefix.str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT);
                } while ($this->model::where('coupon_number', $number)->exists());

                $token = $isQr ? bin2hex(random_bytes(16)) : null;
                $row = [
                    'coupon_number' => $number,
                    'coupon_active' => 1,
                    'coupon_date' => now()->toDateString(),
                    'coupon_expire_date' => $expireDate,
                    'coupon_amount' => $this->money($amountDec),
                    'coupon_balance' => $this->money($amountDec),
                ];
                if ($isQr) {
                    $row['coupon_token'] = $token;
                }
                DB::table((new $this->model)->getTable())->insert($row);

                $issued[] = [
                    'coupon_number' => $number,
                    'coupon_token' => $token,
                    'qr_payload' => $isQr ? "@coupon={$number}@token={$token}" : null,
                ];
            }

            $this->audit('issue', $this->type, $issued[0]['coupon_number'] ?? '', '0', (string) $quantity, "Emisión de {$quantity} cupones de {$this->money($amountDec)}", $actor, $ip);

            return $issued;
        });
    }

    /** Activa o desactiva un cupón (panel), con auditoría. */
    public function setActive(int $couponId, bool $active, string $reason, Authenticatable $actor, ?string $ip): void
    {
        DB::transaction(function () use ($couponId, $active, $reason, $actor, $ip) {
            $coupon = $this->model::where('coupon_id', $couponId)->lockForUpdate()->first();
            if (! $coupon) {
                throw new CouponException('Coupon not found', 404);
            }
            $old = (int) $coupon->coupon_active === 1 ? 'active' : 'inactive';
            $this->model::where('coupon_id', $couponId)->update(['coupon_active' => $active ? 1 : 0]);
            $this->audit($active ? 'activate' : 'deactivate', $this->type, $coupon->coupon_number, $old, $active ? 'active' : 'inactive', $reason, $actor, $ip);
        });
    }

    private function tokenMatches(Model $coupon, ?string $token): bool
    {
        return $token !== null && $coupon->coupon_token !== null
            && hash_equals((string) $coupon->coupon_token, $token);
    }

    private function isUsable(Model $coupon): bool
    {
        if ((int) $coupon->coupon_active !== 1) {
            return false;
        }
        $expire = $coupon->coupon_expire_date;
        if ($expire && strtotime((string) $expire) !== false && strtotime((string) $expire) + 86399 < time()) {
            return false;
        }

        return true;
    }

    /** Modo de redondeo compatible con brick/math < 0.13 (constantes) y >= 0.13 (enum). */
    private static function rounding(): mixed
    {
        return enum_exists(RoundingMode::class) ? constant(RoundingMode::class.'::HalfUp') : constant(RoundingMode::class.'::HALF_UP');
    }

    private function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(2, self::rounding());
    }

    private function money(mixed $value): string
    {
        return (string) ($value instanceof BigDecimal ? $value : $this->decimal($value))
            ->toScale(2, self::rounding());
    }

    private function ymd(mixed $date): string
    {
        return $date ? date('Ymd', strtotime((string) $date)) : '';
    }
}
