<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\AdjustmentRequest;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\Device;
use App\Models\User;
use App\Support\AdminRbac;
use App\Support\CouponToken;
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
    public function redeem(array $numbers, ?array $tokens, string $amount, ?Device $device, ?User $user, ?string $ip, ?string $locationId = null, ?int $dispenser = null): array
    {
        $dispenser = self::resolveDispenser($dispenser, $device);

        try {
            $result = $this->doRedeem($numbers, $tokens, $amount, $device, $user, $ip, $locationId, $dispenser);
        } catch (CouponException $e) {
            // Los bloqueos por límite quedan como alerta (fuera de la transacción revertida)
            if (isset($e->extra()['limit'])) {
                [$locId] = self::locationFor($locationId, $device);
                SecurityAlerts::raise('limit_blocked', 'medium', "Cobro bloqueado por límite ({$e->extra()['limit']}) de {$amount}.", $e->extra()['coupon_number'] ?? null, $user?->getKey(), $locId, ['limit' => $e->extra()['limit']]);
            }
            throw $e;
        }

        SecurityAlerts::afterRedeem($numbers, self::locationFor($locationId, $device)[0], $user?->getKey(), $amount);

        return $result;
    }

    private function doRedeem(array $numbers, ?array $tokens, string $amount, ?Device $device, ?User $user, ?string $ip, ?string $locationId, ?int $dispenser): array
    {
        $required = BigDecimal::of($amount)->toScale(2, self::rounding());

        [$locId, $locSource] = self::locationFor($locationId, $device);

        return DB::transaction(function () use ($numbers, $tokens, $required, $device, $user, $ip, $locId, $locSource, $dispenser) {
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

            CouponLimits::assertRedeemAllowed($numbers, $required, $user, $locId);

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

                $tx = null;
                if ($used->isPositive()) {
                    $this->model::where('coupon_id', $coupon->coupon_id)
                        ->update(['coupon_balance' => $this->money($newBalance)]);

                    $tx = CouponTransaction::create([
                        'coupon_id' => $coupon->coupon_id,
                        'coupon_number' => $coupon->coupon_number,
                        'transaction_amount' => $this->money($used),
                        'coupon_type' => $this->type,
                        'kind' => CouponTransaction::KIND_REDEEM,
                        'customer_id' => $coupon->customer_id,
                        'user_id' => $user?->getKey(),
                        'device_id' => $device?->getKey(),
                        'location_id' => $locId,
                        'location_source' => $locSource,
                        'ip_address' => $ip,
                        'dispenser' => $dispenser,
                    ]);
                }

                $result[] = [
                    'coupon_number' => $coupon->coupon_number,
                    'transaction_id' => $tx?->transaction_id,
                    'balance_initial' => $this->money($initial),
                    'balance' => $this->money($newBalance),
                    'transaction_amount' => $this->money($used),
                ];
            }

            $ids = array_values(array_filter(array_column($result, 'transaction_id')));

            return [
                // Primer movimiento del cobro (comprobante) y todos los que lo componen; fecha ISO 8601
                'transaction_id' => $ids[0] ?? null,
                'transaction_ids' => $ids,
                'date' => now()->toIso8601String(),
                'created_at' => now()->toIso8601String(),
                // Datos de la venta para el ticket y la conciliación
                'amount' => $this->money($required),
                'location_id' => $locId,
                'location_name' => $device?->location?->name,
                'dispenser' => $dispenser,
                'coupons' => $result,
                'sumbalance' => $this->money($sum),
                'sumtransaction_amount' => $this->money($required),
            ];
        });
    }

    /**
     * Fijar el saldo. Con `coupons.restore_requires_approval` (por defecto) no se aplica: crea una solicitud
     * que debe aprobar otro administrador del panel. Sin esa opción se aplica de inmediato.
     *
     * @return array{number: string, balance?: string, status?: string, request_id?: int}
     */
    public function restore(string $number, string $amount, string $reason, Authenticatable $user, ?string $ip): array
    {
        if (! config('coupons.restore_requires_approval')) {
            return $this->applyRestore($number, $amount, $reason, $user, $ip);
        }

        $newBalance = BigDecimal::of($amount)->toScale(2, self::rounding());
        $coupon = $this->model::where('coupon_number', $number)->first();
        if (! $coupon) {
            throw new CouponException('Coupon not found', 404);
        }
        if ($newBalance->isGreaterThan($this->decimal($coupon->coupon_amount))) {
            throw new CouponException('Balance cannot exceed the coupon amount', 422, ['coupon_amount' => $this->money($coupon->coupon_amount)]);
        }

        $request = AdjustmentRequest::create([
            'coupon_type' => $this->type, 'coupon_number' => $number,
            'current_balance' => $this->money($coupon->coupon_balance), 'new_balance' => $this->money($newBalance),
            'reason' => $reason, 'requested_by_user_id' => $user instanceof User ? $user->getKey() : null,
            'requested_by_admin_id' => $user instanceof AdminUser ? $user->getKey() : null, 'ip_address' => $ip, 'status' => AdjustmentRequest::PENDING,
        ]);
        $this->audit('restore_request', $this->type, $number, $this->money($coupon->coupon_balance), $this->money($newBalance), $reason, $user, $ip);
        SecurityAlerts::raise('adjustment_requested', 'medium', "Solicitud de ajuste de saldo del cupón {$number} a {$this->money($newBalance)}: pendiente de aprobación.", $number, $user instanceof User ? (string) $user->getKey() : null);

        return ['coupon_number' => $number, 'number' => $number, 'status' => 'pending_approval', 'request_id' => $request->id];
    }

    /** Aprueba una solicitud de ajuste: debe hacerlo un administrador distinto de quien la pidió. */
    public static function approveAdjustment(AdjustmentRequest $request, AdminUser $approver, ?string $ip): void
    {
        DB::transaction(function () use ($request, $approver, $ip) {
            $req = AdjustmentRequest::whereKey($request->id)->lockForUpdate()->first();
            if (! $req || $req->status !== AdjustmentRequest::PENDING) {
                throw new CouponException('This request was already decided', 409);
            }
            if ($req->requested_by_admin_id !== null && (int) $req->requested_by_admin_id === (int) $approver->getKey()) {
                throw new CouponException('You cannot approve your own request', 403);
            }

            $requester = $req->requested_by_admin_id ? AdminUser::find($req->requested_by_admin_id) : ($req->requested_by_user_id ? User::find($req->requested_by_user_id) : null);
            self::forType($req->coupon_type)->applyRestore($req->coupon_number, (string) $req->new_balance, $req->reason.' (aprobado por '.$approver->name.')', $requester ?? $approver, $ip);
            $req->update(['status' => AdjustmentRequest::APPROVED, 'decided_by_admin_id' => $approver->getKey(), 'decided_at' => now()]);
        });
    }

    public static function rejectAdjustment(AdjustmentRequest $request, AdminUser $approver, ?string $note): void
    {
        $updated = AdjustmentRequest::whereKey($request->id)->where('status', AdjustmentRequest::PENDING)
            ->update(['status' => AdjustmentRequest::REJECTED, 'decided_by_admin_id' => $approver->getKey(), 'decided_at' => now(), 'decision_note' => $note]);
        if (! $updated) {
            throw new CouponException('This request was already decided', 409);
        }
    }

    /** Fija el saldo (nunca por encima del monto original del cupón) y audita. */
    private function applyRestore(string $number, string $amount, string $reason, Authenticatable $user, ?string $ip): array
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

            return ['coupon_number' => $number, 'date' => now()->toIso8601String(), 'number' => $number, 'balance' => $this->money($newBalance)];
        });
    }

    /** Reversa de una transaccion: devuelve el monto y deja una fila compensatoria. */
    public static function reverse(CouponTransaction $original, string $reason, Authenticatable $user, ?string $ip): array
    {
        try {
            return self::doReverse($original, $reason, $user, $ip);
        } catch (CouponException $e) {
            // La alerta se guarda fuera de la transacción revertida
            if (($e->extra()['limit'] ?? null) === 'self_reverse') {
                SecurityAlerts::raise('self_reverse_attempt', 'medium', "Intento de revertir un cobro propio (cupón {$original->coupon_number}).", $original->coupon_number, $user instanceof User ? (string) $user->getKey() : null);
            }
            throw $e;
        }
    }

    private static function doReverse(CouponTransaction $original, string $reason, Authenticatable $user, ?string $ip): array
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

            // Separación de funciones: quien hizo el cobro no lo revierte
            $actorIds = self::actor($user);
            $isOwn = ($actorIds['user_id'] !== null && (string) $tx->user_id === (string) $actorIds['user_id'])
                || ($actorIds['admin_user_id'] !== null && $tx->admin_user_id !== null && (int) $tx->admin_user_id === (int) $actorIds['admin_user_id']);
            if ($isOwn && ! config('coupons.self_reverse_allowed')) {
                throw new CouponException('You cannot reverse your own transaction', 403, ['limit' => 'self_reverse']);
            }
            // Ventana de reversa: pasado el plazo solo un ADMIN
            $window = (int) config('coupons.reverse_window_hours');
            $privileged = $user instanceof AdminUser ? AdminRbac::isSuperAdmin($user) : ($user instanceof User && strtoupper((string) $user->role) === 'ADMIN');
            if ($window > 0 && ! $privileged && $tx->created_at && $tx->created_at->lt(now()->subHours($window))) {
                throw new CouponException("Reversal window of {$window} hours expired; an administrator must reverse it", 422);
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
                'location_source' => $tx->location_source,
                'legacy_device_id' => $tx->legacy_device_id,
                'legacy_site_id' => $tx->legacy_site_id,
                'coupon_type' => $tx->coupon_type,
                ...self::actor($user),
                'ip_address' => $ip,
                'reversed_of' => $tx->transaction_id,
                'kind' => CouponTransaction::KIND_REVERSAL,
                'customer_id' => $tx->customer_id ?? $coupon->customer_id,
            ]);

            $service->audit('reverse', $tx->coupon_type, $tx->coupon_number, $service->money($coupon->coupon_balance),
                $service->money($new), $reason, $user, $ip);

            DB::afterCommit(fn () => SecurityAlerts::afterReverse($user));

            return [
                'transaction_id' => $compensation->transaction_id,
                'original_transaction_id' => $tx->transaction_id,
                'reversal_transaction_id' => $compensation->transaction_id,
                'date' => now()->toIso8601String(),
                'coupon_number' => $tx->coupon_number,
                'balance' => $service->money($new),
            ];
        });
    }

    private function audit(string $action, string $type, string $number, string $old, string $new, string $reason, Authenticatable $user, ?string $ip): void
    {
        CouponAuditLog::create([
            'created_at' => now(), // misma zona horaria que los demás registros (no la del servidor de BD)
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
    public function topUp(string $number, ?string $token, string $amount, ?Device $device, Authenticatable $actor, ?string $ip, bool $verifyToken = true, ?string $note = null, ?string $locationId = null): array
    {
        $amountDec = BigDecimal::of($amount)->toScale(2, self::rounding());

        [$locId, $locSource] = self::locationFor($locationId, $device);

        return DB::transaction(function () use ($number, $token, $amountDec, $device, $actor, $ip, $verifyToken, $note, $locId, $locSource) {
            $coupon = $this->model::where('coupon_number', $number)->lockForUpdate()->first();

            if (! $coupon || ($verifyToken && $this->type === self::TYPE_QR && ! $this->tokenMatches($coupon, $token))) {
                throw new CouponException('Coupon not found', 404);
            }
            if (! $this->isUsable($coupon)) {
                throw new CouponException('Coupon is inactive or expired', 422, ['coupon_number' => $number]);
            }

            TopUpRules::assertAllowed($this->type, $amountDec, $number, $locId, $device !== null || $verifyToken);

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
                'customer_id' => $coupon->customer_id,
                ...self::actor($actor),
                'device_id' => $device?->getKey(),
                'location_id' => $locId,
                'location_source' => $locSource,
                'ip_address' => $ip,
            ]);

            $this->audit('topup', $this->type, $number, $this->money($old), $this->money($new), 'Recarga de '.$this->money($amountDec).($note ? ': '.$note : ''), $actor, $ip);

            return [
                'coupon_number' => $number,
                'date' => now()->toIso8601String(),
                'number' => $number,
                'topup_amount' => $this->money($amountDec),
                'balance' => $this->money($new),
                'transaction_id' => $tx->transaction_id,
            ];
        });
    }

    /**
     * Localidad del movimiento: la del dispositivo registrado (se ignora la que envíe el cliente) y, solo si no hay
     * dispositivo, la que venga en la petición. Devuelve [id, origen].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function locationFor(?string $requested, ?Device $device): array
    {
        // Con un dispositivo registrado manda su localidad: lo que envíe el cliente se ignora.
        if ($device?->location_id) {
            return [(string) $device->location_id, 'device'];
        }

        return $requested !== null && $requested !== '' ? [$requested, 'request'] : [null, null];
    }

    /**
     * Surtidor del cobro. Con un dispositivo cuya localidad tiene surtidores (> 0) debe estar entre 1 y ese número;
     * si la localidad no usa surtidores (0) se ignora lo que envíe el cliente. `coupons.require_dispenser` lo hace obligatorio.
     */
    private static function resolveDispenser(?int $dispenser, ?Device $device): ?int
    {
        $location = $device?->location;
        if ($location === null) {
            return $dispenser;
        }

        $count = (int) $location->dispensers;
        if ($count === 0) {
            return null;
        }
        if ($dispenser === null) {
            if (config('coupons.require_dispenser')) {
                throw new CouponException('A dispenser is required for this location', 422, ['dispensers' => $count], 'dispenser_required');
            }

            return null;
        }
        if ($dispenser < 1 || $dispenser > $count) {
            throw new CouponException('Invalid dispenser for this location', 422, ['dispensers' => $count], 'invalid_dispenser');
        }

        return $dispenser;
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
     * Desactiva (ACTIVE → INACTIVE) o reactiva (INACTIVE → ACTIVE) un cupón desde el panel, con auditoría.
     * Un cupón GENERATED/ASSIGNED/VOID no se activa aquí: eso se hace con una orden de activación del lote.
     */
    public function setActive(int $couponId, bool $active, string $reason, Authenticatable $actor, ?string $ip): void
    {
        DB::transaction(function () use ($couponId, $active, $reason, $actor, $ip) {
            $coupon = $this->model::where('coupon_id', $couponId)->lockForUpdate()->first();
            if (! $coupon) {
                throw new CouponException('Coupon not found', 404);
            }
            $from = $active ? Coupon::STATUS_INACTIVE : Coupon::STATUS_ACTIVE;
            if (($coupon->status ?? Coupon::STATUS_ACTIVE) !== $from) {
                throw new CouponException('Only an active coupon can be deactivated and an inactive one reactivated; activate batches from the batch page', 422);
            }
            $this->model::where('coupon_id', $couponId)->update([
                'status' => $active ? Coupon::STATUS_ACTIVE : Coupon::STATUS_INACTIVE,
                'coupon_active' => $active ? 1 : 0,
            ]);
            $this->audit($active ? 'activate' : 'deactivate', $this->type, $coupon->coupon_number, strtolower($from), $active ? 'active' : 'inactive', $reason, $actor, $ip);
        });
    }

    private function tokenMatches(Model $coupon, ?string $token): bool
    {
        return CouponToken::matches($coupon->coupon_token, $token);
    }

    private function isUsable(Model $coupon): bool
    {
        if ((int) $coupon->coupon_active !== 1 || ($coupon->status ?? Coupon::STATUS_ACTIVE) !== Coupon::STATUS_ACTIVE) {
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
