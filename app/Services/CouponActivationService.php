<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\ActivationOrder;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Asignar a un cliente y activar (ahora o en una fecha) una selección de cupones de un lote.
 *
 * Selección: `all` (todos los elegibles), `range` (secuencia desde-hasta), `quantity` (los primeros N
 * elegibles) o `list` (números concretos; todos deben existir en el lote y ser elegibles).
 * Cada operación deja una orden (`activation_orders`) y una fila de auditoría.
 * Quien generó el lote no puede asignarlo ni activarlo (separación de funciones).
 */
class CouponActivationService
{
    public const SELECTIONS = ['all', 'range', 'quantity', 'list'];

    /**
     * Cupones elegibles según la selección, sin modificar nada.
     *
     * @param  list<string>  $statuses  estados de origen aceptados
     * @return list<int> coupon_id ordenados por secuencia
     */
    public function resolve(CouponBatch $batch, string $type, array $input, array $statuses, ?Customer $customer = null, bool $lock = false): array
    {
        $query = $batch->coupons()->whereIn('status', $statuses)->orderBy('seq');

        // Un cupón ya asignado a otro cliente no se puede tomar
        if ($customer !== null && in_array(Coupon::STATUS_ASSIGNED, $statuses, true)) {
            $query->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', $customer->getKey()));
        }

        switch ($type) {
            case 'range':
                $from = (int) ($input['from'] ?? 0);
                $to = (int) ($input['to'] ?? 0);
                if ($from < 1 || $to < $from) {
                    throw new CouponException('Invalid range', 422);
                }
                $query->whereBetween('seq', [$from, $to]);
                break;
            case 'quantity':
                $n = (int) ($input['quantity'] ?? 0);
                if ($n < 1) {
                    throw new CouponException('Invalid quantity', 422);
                }
                $query->limit($n);
                break;
            case 'list':
                $numbers = array_values(array_unique(array_filter(array_map('trim', (array) ($input['numbers'] ?? [])))));
                if ($numbers === []) {
                    throw new CouponException('The list is empty', 422);
                }
                $found = $batch->coupons()->whereIn('coupon_number', $numbers)->pluck('status', 'coupon_number');
                $missing = array_values(array_diff($numbers, $found->keys()->all()));
                if ($missing !== []) {
                    throw new CouponException('Some numbers do not belong to this batch', 422, ['unknown' => array_slice($missing, 0, 10), 'unknown_count' => count($missing)]);
                }
                $ineligible = $found->filter(fn ($status) => ! in_array($status, $statuses, true));
                if ($ineligible->isNotEmpty()) {
                    throw new CouponException('Some coupons are not in an eligible state', 422, ['not_eligible' => $ineligible->keys()->take(10)->values()->all(), 'not_eligible_count' => $ineligible->count()]);
                }
                $query->whereIn('coupon_number', $numbers);
                break;
            case 'all':
                break;
            default:
                throw new CouponException('Invalid selection', 422);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $ids = $query->pluck('coupon_id')->map(fn ($id) => (int) $id)->all();

        if ($type === 'list' && count($ids) !== count(array_unique(array_filter(array_map('trim', (array) ($input['numbers'] ?? [])))))) {
            throw new CouponException('Some coupons are reserved for another customer', 422);
        }

        return $ids;
    }

    /** Vista previa: cuántos cupones se verían afectados. */
    public function preview(CouponBatch $batch, string $mode, string $type, array $input, Customer $customer): array
    {
        $this->assertBatchUsable($batch);
        $ids = $this->resolve($batch, $type, $input, $this->sourceStatuses($mode), $customer);
        if ($ids === []) {
            throw new CouponException('There are no eligible coupons for this selection', 422);
        }
        $this->assertSize(count($ids));

        $first = $batch->coupons()->whereIn('coupon_id', [$ids[0], $ids[count($ids) - 1]])->orderBy('seq')->pluck('seq')->all();

        return ['count' => count($ids), 'first_seq' => $first[0] ?? null, 'last_seq' => end($first) ?: null];
    }

    /** Asigna (`assign`), activa ahora (`now`) o programa (`scheduled`) la selección para un cliente. */
    public function submit(CouponBatch $batch, Customer $customer, string $mode, ?CarbonInterface $at, string $type, array $input, ?string $reason, AdminUser $actor, ?string $ip): ActivationOrder
    {
        if (! in_array($mode, [ActivationOrder::MODE_ASSIGN, ActivationOrder::MODE_NOW, ActivationOrder::MODE_SCHEDULED], true)) {
            throw new CouponException('Invalid mode', 422);
        }
        $this->assertSeparationOfDuties($batch, $actor);
        $this->assertBatchUsable($batch);
        if (! $customer->is_active) {
            throw new CouponException('The customer is not active', 422);
        }
        if ($mode === ActivationOrder::MODE_SCHEDULED) {
            if ($at === null || $at->lt(now()->addMinute()) || $at->gt(now()->addYear())) {
                throw new CouponException('The activation date must be at least one minute from now and within a year', 422);
            }
        } else {
            $at = null;
        }

        return DB::transaction(function () use ($batch, $customer, $mode, $at, $type, $input, $reason, $actor, $ip) {
            $ids = $this->resolve($batch, $type, $input, $this->sourceStatuses($mode), $customer, lock: true);
            if ($ids === []) {
                throw new CouponException('There are no eligible coupons for this selection', 422);
            }
            $this->assertSize(count($ids));

            $order = ActivationOrder::create([
                'batch_id' => $batch->getKey(),
                'customer_id' => $customer->getKey(),
                'mode' => $mode,
                'activate_at' => $at,
                'status' => $mode === ActivationOrder::MODE_SCHEDULED ? ActivationOrder::STATUS_PENDING : ActivationOrder::STATUS_APPLIED,
                'selection_type' => $type,
                'selection' => array_diff_key($input, ['numbers' => true]) + ($type === 'list' ? ['numbers_count' => count((array) ($input['numbers'] ?? []))] : []),
                'coupons_count' => count($ids),
                'reason' => $reason,
                'requested_by_admin_id' => $actor->getKey(),
                'applied_at' => $mode === ActivationOrder::MODE_SCHEDULED ? null : now(),
            ]);

            $now = now();
            $active = $mode === ActivationOrder::MODE_NOW;
            $changes = [
                'status' => $active ? Coupon::STATUS_ACTIVE : Coupon::STATUS_ASSIGNED,
                'coupon_active' => $active ? 1 : 0,
                'customer_id' => $customer->getKey(),
                'activation_order_id' => $order->getKey(),
            ];
            $from = $this->sourceStatuses($mode);
            $table = (new ($batch->couponModel()))->getTable();

            foreach (array_chunk($ids, 1000) as $chunk) {
                $updated = DB::table($table)->whereIn('coupon_id', $chunk)->whereIn('status', $from)->update($changes + [
                    'assigned_at' => DB::raw('COALESCE(assigned_at, '.DB::getPdo()->quote($now->toDateTimeString()).')'),
                    'activated_at' => $active ? $now : null,
                ]);
                if ($updated !== count($chunk)) {
                    throw new CouponException('The selection changed while it was being applied; try again', 409);
                }
            }

            $action = ['assign' => 'assign', 'now' => 'activate', 'scheduled' => 'schedule'][$mode];
            CouponBatchGenerator::audit($batch, $action, $actor, $ip, '', (string) count($ids),
                "{$customer->name}: ".count($ids).' cupones'.($at ? ' para el '.$at->format('d/m/Y H:i') : '').($reason ? " — {$reason}" : ''), $order->getKey());

            return $order;
        });
    }

    /** Aplica las órdenes programadas cuya fecha ya llegó. Idempotente: se puede correr cada minuto. */
    public function applyDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $applied = 0;

        ActivationOrder::query()
            ->where('status', ActivationOrder::STATUS_PENDING)->where('activate_at', '<=', $now)
            ->orderBy('activate_at')->pluck('id')
            ->each(function (string $id) use ($now, &$applied) {
                DB::transaction(function () use ($id, $now, &$applied) {
                    $order = ActivationOrder::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $order || $order->status !== ActivationOrder::STATUS_PENDING) {
                        return;
                    }
                    $batch = CouponBatch::findOrFail($order->batch_id);
                    $table = (new ($batch->couponModel()))->getTable();

                    $count = 0;
                    DB::table($table)->where('activation_order_id', $order->getKey())->where('status', Coupon::STATUS_ASSIGNED)
                        ->orderBy('coupon_id')->select('coupon_id')->chunkById(1000, function ($rows) use ($table, $now, &$count) {
                            $count += DB::table($table)->whereIn('coupon_id', $rows->pluck('coupon_id'))->where('status', Coupon::STATUS_ASSIGNED)
                                ->update(['status' => Coupon::STATUS_ACTIVE, 'coupon_active' => 1, 'activated_at' => $now]);
                        }, 'coupon_id');

                    $order->forceFill(['status' => ActivationOrder::STATUS_APPLIED, 'applied_at' => $now])->save();
                    CouponBatchGenerator::audit($batch, 'activate', null, null, (string) $order->coupons_count, (string) $count,
                        "Activación programada aplicada ({$count} de {$order->coupons_count} cupones)", $order->getKey());
                    $applied++;
                });
            });

        return $applied;
    }

    /** Cancela una orden programada: los cupones quedan asignados al cliente, sin activarse. */
    public function cancel(ActivationOrder $order, ?string $reason, AdminUser $actor, ?string $ip): void
    {
        DB::transaction(function () use ($order, $reason, $actor, $ip) {
            $locked = ActivationOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== ActivationOrder::STATUS_PENDING) {
                throw new CouponException('Only a pending scheduled activation can be cancelled', 422);
            }
            $locked->forceFill([
                'status' => ActivationOrder::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_admin_id' => $actor->getKey(),
            ])->save();
            CouponBatchGenerator::audit($locked->batch, 'cancel_activation', $actor, $ip, (string) $locked->coupons_count, '0',
                'Activación programada cancelada'.($reason ? " — {$reason}" : ''), $locked->getKey());
        });
    }

    /** Libera cupones asignados (ASSIGNED → GENERATED) que no tengan una activación programada pendiente. */
    public function unassign(CouponBatch $batch, string $type, array $input, ?string $reason, AdminUser $actor, ?string $ip): int
    {
        $this->assertSeparationOfDuties($batch, $actor);

        return DB::transaction(function () use ($batch, $type, $input, $reason, $actor, $ip) {
            $ids = $this->resolve($batch, $type, $input, [Coupon::STATUS_ASSIGNED], null, lock: true);
            $table = (new ($batch->couponModel()))->getTable();
            $pending = ActivationOrder::query()->where('batch_id', $batch->getKey())->where('status', ActivationOrder::STATUS_PENDING)->pluck('id')->all();
            $count = 0;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $count += DB::table($table)->whereIn('coupon_id', $chunk)->where('status', Coupon::STATUS_ASSIGNED)
                    ->where(fn ($q) => $q->whereNull('activation_order_id')->orWhereNotIn('activation_order_id', $pending))
                    ->update(['status' => Coupon::STATUS_GENERATED, 'customer_id' => null, 'assigned_at' => null, 'activation_order_id' => null]);
            }
            if ($count === 0) {
                throw new CouponException('No assigned coupons without a pending scheduled activation match this selection', 422);
            }
            CouponBatchGenerator::audit($batch, 'unassign', $actor, $ip, (string) $count, '0', "{$count} cupones liberados".($reason ? " — {$reason}" : ''));

            return $count;
        });
    }

    /** Anula los cupones aún no activos del lote (los activos no se tocan) y cancela sus órdenes pendientes. */
    public function voidBatch(CouponBatch $batch, ?string $reason, AdminUser $actor, ?string $ip): int
    {
        if ($batch->status === CouponBatch::STATUS_VOID) {
            throw new CouponException('The batch is already void', 422);
        }

        return DB::transaction(function () use ($batch, $reason, $actor, $ip) {
            $table = (new ($batch->couponModel()))->getTable();
            $count = DB::table($table)->where('batch_id', $batch->getKey())->whereIn('status', [Coupon::STATUS_GENERATED, Coupon::STATUS_ASSIGNED])
                ->update(['status' => Coupon::STATUS_VOID, 'coupon_active' => 0, 'activation_order_id' => null]);
            ActivationOrder::query()->where('batch_id', $batch->getKey())->where('status', ActivationOrder::STATUS_PENDING)
                ->update(['status' => ActivationOrder::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_admin_id' => $actor->getKey()]);
            $batch->forceFill(['status' => CouponBatch::STATUS_VOID, 'export_path' => null])->save();
            CouponBatchGenerator::audit($batch, 'void_batch', $actor, $ip, '', (string) $count, "{$count} cupones anulados".($reason ? " — {$reason}" : ''));

            return $count;
        });
    }

    /** @return list<string> */
    private function sourceStatuses(string $mode): array
    {
        return $mode === ActivationOrder::MODE_ASSIGN
            ? [Coupon::STATUS_GENERATED]
            : [Coupon::STATUS_GENERATED, Coupon::STATUS_ASSIGNED];
    }

    private function assertBatchUsable(CouponBatch $batch): void
    {
        if ($batch->status !== CouponBatch::STATUS_READY) {
            throw new CouponException('The batch is not ready', 422);
        }
    }

    private function assertSize(int $count): void
    {
        if ($count > (int) config('coupons.max_activation_size')) {
            throw new CouponException('The selection exceeds the maximum per operation', 422, ['max' => (int) config('coupons.max_activation_size')]);
        }
    }

    private function assertSeparationOfDuties(CouponBatch $batch, AdminUser $actor): void
    {
        if (config('coupons.separation_of_duties') && (int) $batch->created_by_admin_id === (int) $actor->getKey()) {
            throw new CouponException('The person who generated a batch cannot assign or activate it', 403);
        }
    }
}
