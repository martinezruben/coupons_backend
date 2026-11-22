<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponReplacement;
use App\Support\CouponToken;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Cupón perdido: el saldo se traslada a un cupón nuevo (mismo cliente, lote y vencimiento) y el viejo queda anulado
 * con saldo 0, así quien lo encuentre no puede cobrarlo. Por defecto lo pide un administrador y lo aprueba otro.
 * El traslado no es un cobro ni una recarga: no cuenta en el cierre de caja; queda en la línea de tiempo y la auditoría.
 */
class CouponReplacementService
{
    /** @return array{0: class-string, 1: string, 2: string} modelo, tabla y tipo */
    private static function kind(string $type): array
    {
        return $type === 'QRCODE' ? [Coupon::class, 'coupons', 'QRCODE'] : [BarcodeCoupon::class, 'coupons_codbar', 'CODBAR'];
    }

    public static function request(string $type, int $couponId, string $reason, AdminUser $admin, ?string $ip): CouponReplacement
    {
        [$model] = self::kind($type);
        $coupon = $model::where('coupon_id', $couponId)->first();
        if (! $coupon) {
            throw new CouponException('Coupon not found', 404);
        }
        self::assertReplaceable($coupon);
        if (CouponReplacement::where('coupon_type', $type)->where('old_coupon_id', $couponId)->where('status', CouponReplacement::PENDING)->exists()) {
            throw new CouponException('There is already a pending replacement for this coupon', 409, [], 'replacement_pending');
        }

        $rep = CouponReplacement::create([
            'coupon_type' => $type, 'old_coupon_id' => $couponId, 'old_coupon_number' => $coupon->coupon_number,
            'balance' => self::money($coupon->coupon_balance), 'reason' => $reason, 'requested_by_admin_id' => $admin->getKey(), 'ip_address' => $ip,
            'status' => CouponReplacement::PENDING,
        ]);
        self::audit('replace_request', $type, $coupon->coupon_number, self::money($coupon->coupon_balance), null, $reason, $admin, $ip);
        SecurityAlerts::raise('replacement_requested', 'medium', "Solicitud de reemplazo del cupón {$coupon->coupon_number} (saldo ".self::money($coupon->coupon_balance).'): pendiente de aprobación.', $coupon->coupon_number);

        // Sin aprobación obligatoria el reemplazo se aplica de inmediato
        if (! config('coupons.replace_requires_approval')) {
            return self::approve($rep, $admin, $ip, allowSelf: true);
        }

        return $rep;
    }

    public static function approve(CouponReplacement $request, AdminUser $approver, ?string $ip, bool $allowSelf = false): CouponReplacement
    {
        $result = DB::transaction(function () use ($request, $approver, $ip, $allowSelf) {
            $rep = CouponReplacement::whereKey($request->id)->lockForUpdate()->first();
            if (! $rep || $rep->status !== CouponReplacement::PENDING) {
                throw new CouponException('This request was already decided', 409);
            }
            if (! $allowSelf && (int) $rep->requested_by_admin_id === (int) $approver->getKey()) {
                throw new CouponException('You cannot approve your own request', 403);
            }

            [$model, $table, $type] = self::kind($rep->coupon_type);
            $old = $model::where('coupon_id', $rep->old_coupon_id)->lockForUpdate()->first();
            if (! $old) {
                throw new CouponException('Coupon not found', 404);
            }
            self::assertReplaceable($old);
            $balance = self::money($old->coupon_balance); // el saldo vigente, no el de la solicitud

            $prefix = preg_match('/^(.*?)\d{12}$/', (string) $old->coupon_number, $m) ? $m[1] : '';
            $number = (new CouponBatchGenerator)->freshNumbers(1, $prefix)[0];

            $row = [
                'coupon_number' => $number, 'coupon_active' => 1, 'status' => Coupon::STATUS_ACTIVE, 'coupon_date' => now()->toDateString(),
                'coupon_expire_date' => $old->coupon_expire_date, 'coupon_amount' => $balance, 'coupon_balance' => $balance,
                'batch_id' => $old->batch_id, 'customer_id' => $old->customer_id, 'assigned_at' => now(), 'activated_at' => now(),
            ];
            if ($type === 'QRCODE') {
                $token = CouponToken::generate();
                $row['coupon_token'] = CouponToken::hash($token);
                $row['coupon_token_enc'] = CouponToken::seal($token);
            }
            $newId = DB::table($table)->insertGetId($row, 'coupon_id');

            DB::table($table)->where('coupon_id', $old->coupon_id)->update(['coupon_balance' => '0.00', 'coupon_active' => 0, 'status' => Coupon::STATUS_VOID]);

            self::audit('replace_out', $type, $old->coupon_number, $balance, '0.00', 'Reemplazado por '.$number.': '.$rep->reason, $approver, $ip);
            self::audit('replace_in', $type, $number, null, $balance, 'Reemplaza al cupón '.$old->coupon_number.': '.$rep->reason, $approver, $ip);
            $rep->update(['status' => CouponReplacement::APPROVED, 'balance' => $balance, 'new_coupon_id' => $newId, 'new_coupon_number' => $number, 'decided_by_admin_id' => $approver->getKey(), 'decided_at' => now()]);

            return $rep;
        });

        SecurityAlerts::raise('replacement_applied', 'low', "Cupón {$result->old_coupon_number} reemplazado por {$result->new_coupon_number} (saldo {$result->balance}).", $result->old_coupon_number);

        return $result;
    }

    public static function reject(CouponReplacement $request, AdminUser $approver, ?string $note): void
    {
        $updated = CouponReplacement::whereKey($request->id)->where('status', CouponReplacement::PENDING)
            ->update(['status' => CouponReplacement::REJECTED, 'decided_by_admin_id' => $approver->getKey(), 'decided_at' => now(), 'decision_note' => $note]);
        if (! $updated) {
            throw new CouponException('This request was already decided', 409);
        }
        self::audit('replace_reject', $request->coupon_type, $request->old_coupon_number, null, null, 'Reemplazo rechazado'.($note ? ': '.$note : ''), $approver, null);
    }

    /** Solo un cupón activo o inactivo con saldo se puede reemplazar. */
    private static function assertReplaceable(object $coupon): void
    {
        $status = $coupon->status ?? Coupon::STATUS_ACTIVE;
        if (! in_array($status, [Coupon::STATUS_ACTIVE, Coupon::STATUS_INACTIVE], true)) {
            throw new CouponException('Only an active or inactive coupon can be replaced', 422, [], 'coupon_not_replaceable');
        }
        if (! BigDecimal::of(self::money($coupon->coupon_balance))->isPositive()) {
            throw new CouponException('The coupon has no balance to move', 422, [], 'coupon_without_balance');
        }
    }

    private static function money(mixed $v): string
    {
        return (string) BigDecimal::of((string) ($v ?? '0'))->toScale(2, enum_exists(RoundingMode::class) ? constant(RoundingMode::class.'::HalfUp') : constant(RoundingMode::class.'::HALF_UP'));
    }

    private static function audit(string $action, string $type, string $number, ?string $old, ?string $new, string $reason, AdminUser $admin, ?string $ip): void
    {
        CouponAuditLog::create([
            'created_at' => now(), 'admin_user_id' => $admin->getKey(), 'action' => $action, 'coupon_type' => $type, 'coupon_number' => $number,
            'old_value' => $old, 'new_value' => $new, 'reason' => mb_substr($reason, 0, 255), 'ip_address' => $ip,
        ]);
    }
}
