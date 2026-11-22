<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCouponDevice;
use App\Models\CouponTransaction;
use App\Models\Location;
use App\Models\User;
use App\Support\SensitiveData;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ventas recientes para la app: reimprimir un recibo, conciliar con el servidor y recuperar una venta que el servidor
 * cobró pero cuya respuesta se perdió (`?key=<Idempotency-Key de esa venta>`). Una venta agrupa los movimientos de un
 * mismo cobro. Los números de cupón salen enmascarados (el de barras es un secreto al portador).
 */
class SalesController extends Controller
{
    public function recent(Request $request): JsonResponse
    {
        $f = $request->validate([
            'scope' => ['nullable', 'in:mine,location'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'key' => ['nullable', 'string', 'max:100'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $scope = $f['scope'] ?? 'mine';
        $device = $request->attributes->get(EnsureCouponDevice::ATTRIBUTE);

        $query = CouponTransaction::query()->where('kind', CouponTransaction::KIND_REDEEM)
            ->where('created_at', '>=', now()->subHours((int) ($f['hours'] ?? 24)));

        if ($scope === 'location') {
            // Las ventas de toda la localidad las ve quien puede revertir (supervisor) y desde un terminal registrado
            if (! $user->tokenCan('coupon:reverse')) {
                throw new CouponException('Not allowed', 403, [], 'forbidden');
            }
            if ($device === null || ! $device->location_id) {
                throw new CouponException('Device not authorized', 403, [], 'device_not_authorized');
            }
            $query->where('location_id', $device->location_id);
        } else {
            $query->where('user_id', $user->getKey());
        }
        if (! empty($f['key'])) {
            $query->where('sale_ref', $f['key']);
        }

        $rows = $query->orderByDesc('transaction_id')->limit(500)->get();
        $reversed = CouponTransaction::query()->where('kind', CouponTransaction::KIND_REVERSAL)->whereIn('reversed_of', $rows->pluck('transaction_id'))->pluck('reversed_of')->flip();
        $cashiers = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('username', 'id');
        $locations = Location::query()->whereIn('id', $rows->pluck('location_id')->filter()->unique())->pluck('name', 'id');

        // Un cobro = los movimientos con la misma referencia y cajero (los anteriores a la referencia, uno por movimiento)
        $sales = $rows->groupBy(fn ($t) => ($t->sale_ref ?? 'tx-'.$t->transaction_id).'|'.$t->user_id)->map(function ($group) use ($reversed, $cashiers, $locations) {
            $ordered = $group->sortBy('transaction_id')->values();
            $first = $ordered->first();
            $reversedCount = $ordered->filter(fn ($t) => $reversed->has($t->transaction_id))->count();

            return [
                'sale_id' => $first->sale_ref,
                'transaction_id' => $first->transaction_id,
                'transaction_ids' => $ordered->pluck('transaction_id')->all(),
                'date' => $first->created_at?->toIso8601String(),
                'amount' => (string) $ordered->reduce(fn (BigDecimal $c, $t) => $c->plus((string) $t->transaction_amount), BigDecimal::zero())->toScale(2),
                'dispenser' => $first->dispenser,
                'location_id' => $first->location_id,
                'location_name' => $first->location_id ? ($locations[$first->location_id] ?? null) : null,
                'cashier' => $first->user_id ? ($cashiers[$first->user_id] ?? null) : null,
                'status' => $reversedCount === 0 ? 'completed' : ($reversedCount === $ordered->count() ? 'reversed' : 'partially_reversed'),
                'coupons' => $ordered->map(fn ($t) => [
                    'coupon_number' => SensitiveData::maskNumber((string) $t->coupon_number),
                    'transaction_id' => $t->transaction_id,
                    'applied' => number_format((float) $t->transaction_amount, 2, '.', ''),
                    'balance' => $t->balance_after !== null ? number_format((float) $t->balance_after, 2, '.', '') : null,
                    'reversed' => $reversed->has($t->transaction_id),
                ])->all(),
            ];
        })->sortByDesc('transaction_id')->take((int) ($f['limit'] ?? 20))->values()->all();

        return response()->json(['scope' => $scope, 'count' => count($sales), 'sales' => $sales])->header('Cache-Control', 'no-store');
    }
}
