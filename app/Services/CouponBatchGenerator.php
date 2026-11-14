<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponBatch;
use App\Support\CouponToken;
use App\Support\RoleLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generación masiva de lotes: números aleatorios, token aleatorio de 128 bits (solo QR) guardado
 * como hash y un CSV con los valores en claro, que es el único lugar donde el token existe.
 * Todo o nada: si algo falla, se borran los cupones y el archivo parciales y el lote queda `failed`.
 */
class CouponBatchGenerator
{
    public const MAX_QUANTITY = 20000;

    private const CHUNK = 1000;

    /** Crea el lote en estado `generating` (la generación corre aparte, en cola). */
    public static function create(array $data, AdminUser $admin, ?string $ip): CouponBatch
    {
        self::assertWithinRoleLimits((int) $data['quantity'], (float) $data['amount'], $admin);

        $batch = DB::transaction(function () use ($data, $admin) {
            return CouponBatch::create([
                'code' => self::nextCode(),
                'name' => $data['name'],
                'coupon_type' => $data['coupon_type'],
                'quantity' => (int) $data['quantity'],
                'amount' => number_format((float) $data['amount'], 2, '.', ''), // ya validado a 2 decimales
                'prefix' => strtoupper((string) ($data['prefix'] ?? '')) ?: null,
                'expire_date' => $data['expire_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => CouponBatch::STATUS_GENERATING,
                'created_by_admin_id' => $admin->getKey(),
            ]);
        });

        self::audit($batch, 'batch_create', $admin, $ip, '0', (string) $batch->quantity, "Lote {$batch->code}: {$batch->quantity} cupones");

        return $batch;
    }

    /** Límites del rol del administrador sobre la cantidad y el valor total (cantidad × monto) del lote. */
    public static function assertWithinRoleLimits(int $quantity, float $amount, AdminUser $admin): void
    {
        $maxQuantity = RoleLimits::for($admin, 'max_batch_quantity');
        if ($maxQuantity !== null && $quantity > $maxQuantity) {
            throw new CouponException('Your role can generate at most '.$maxQuantity.' coupons per batch', 422, ['max_quantity' => $maxQuantity]);
        }
        $maxValue = RoleLimits::for($admin, 'max_batch_value');
        if ($maxValue !== null && round($quantity * $amount, 2) > $maxValue) {
            throw new CouponException('The total value of the batch exceeds the limit of your role', 422, ['max_value' => number_format($maxValue, 2, '.', '')]);
        }
    }

    public function generate(CouponBatch $batch): void
    {
        $isQr = $batch->coupon_type === 'QRCODE';
        $model = $batch->couponModel();
        $table = (new $model)->getTable();
        $path = 'exports/coupons/'.$batch->getKey().'.csv';
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports/coupons');

        try {
            $handle = fopen($disk->path($path), 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $isQr
                ? ['lote', 'secuencia', 'numero', 'token', 'contenido_qr', 'monto', 'vence']
                : ['lote', 'secuencia', 'numero', 'monto', 'vence']);

            $amount = number_format((float) $batch->amount, 2, '.', '');
            $expire = $batch->expire_date?->toDateString();
            $generated = 0;

            while ($generated < $batch->quantity) {
                $size = min(self::CHUNK, $batch->quantity - $generated);
                $numbers = $this->freshNumbers($size, (string) $batch->prefix);
                $rows = [];
                $csv = [];

                foreach ($numbers as $i => $number) {
                    $seq = $generated + $i + 1;
                    $token = $isQr ? CouponToken::generate() : null;
                    $row = [
                        'coupon_number' => $number,
                        'coupon_active' => 0,
                        'status' => Coupon::STATUS_GENERATED,
                        'coupon_date' => now()->toDateString(),
                        'coupon_expire_date' => $expire,
                        'coupon_amount' => $amount,
                        'coupon_balance' => $amount,
                        'batch_id' => $batch->getKey(),
                        'seq' => $seq,
                    ];
                    if ($isQr) {
                        $row['coupon_token'] = CouponToken::hash($token);
                    }
                    $rows[] = $row;
                    $csv[] = $isQr
                        ? [$batch->code, $seq, $number, $token, "@coupon={$number}@token={$token}", $amount, $expire]
                        : [$batch->code, $seq, $number, $amount, $expire];
                }

                DB::table($table)->insert($rows);
                foreach ($csv as $line) {
                    fputcsv($handle, $line);
                }
                $generated += $size;
            }
            fclose($handle);

            $batch->forceFill([
                'status' => CouponBatch::STATUS_READY,
                'generated_count' => $generated,
                'generated_at' => now(),
                'export_path' => $path,
                'export_expires_at' => now()->addHours((int) config('coupons.export_ttl_hours')),
            ])->save();

            self::audit($batch, 'batch_generate', null, null, '0', (string) $generated, "Generación del lote {$batch->code} terminada");
        } catch (\Throwable $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            try {
                DB::table($table)->where('batch_id', $batch->getKey())->delete();
            } catch (\Throwable $cleanup) {
                report($cleanup); // la limpieza no debe ocultar el error original
            }
            $disk->delete($path);
            $batch->forceFill([
                'status' => CouponBatch::STATUS_FAILED,
                'generated_count' => 0,
                'export_path' => null,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
            report($e);
            self::audit($batch, 'batch_failed', null, null, '0', '0', 'Falló la generación del lote');
        }
    }

    /** @return list<string> números únicos en ambas tablas de cupones y dentro del propio lote */
    private function freshNumbers(int $count, string $prefix): array
    {
        $numbers = [];
        while (count($numbers) < $count) {
            $candidates = [];
            while (count($candidates) < $count - count($numbers)) {
                $candidates[$prefix.str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT)] = true;
            }
            $candidates = array_keys($candidates);
            $taken = array_merge(
                DB::table('coupons')->whereIn('coupon_number', $candidates)->pluck('coupon_number')->all(),
                DB::table('coupons_codbar')->whereIn('coupon_number', $candidates)->pluck('coupon_number')->all(),
            );
            $numbers = array_values(array_unique(array_merge($numbers, array_diff($candidates, $taken))));
        }

        return array_slice($numbers, 0, $count);
    }

    private static function nextCode(): string
    {
        $base = 'LOT-'.now()->format('Ymd').'-';
        $n = CouponBatch::query()->where('code', 'like', $base.'%')->count() + 1;
        while (CouponBatch::query()->where('code', $base.str_pad((string) $n, 3, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $base.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    public static function audit(CouponBatch $batch, string $action, ?AdminUser $admin, ?string $ip, string $old, string $new, string $reason, ?string $orderId = null): void
    {
        CouponAuditLog::create([
            'admin_user_id' => $admin?->getKey(),
            'action' => $action,
            'coupon_type' => $batch->coupon_type,
            'old_value' => $old,
            'new_value' => $new,
            'reason' => mb_substr($reason, 0, 255),
            'ip_address' => $ip,
            'batch_id' => $batch->getKey(),
            'activation_order_id' => $orderId,
        ]);
    }
}
