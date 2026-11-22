<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCouponDevice;
use App\Http\Requests\BalanceRequest;
use App\Http\Requests\RedeemRequest;
use App\Http\Requests\RestoreRequest;
use App\Http\Requests\TopUpRequest;
use App\Models\Device;
use App\Models\User;
use App\Services\CouponService;
use App\Services\DeviceBinding;
use App\Services\SecurityAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseCouponController extends Controller
{
    abstract protected function service(): CouponService;

    public function balance(BalanceRequest $request): JsonResponse
    {
        try {
            return response()->json(
                $this->service()->balance($request->input('coupon_number'), $request->input('coupon_token'))
            );
        } catch (CouponException $e) {
            SecurityAlerts::failedLookup($request->user()?->getKey(), $request->ip());
            throw $e;
        }
    }

    public function transaction(RedeemRequest $request): JsonResponse
    {
        return response()->json($this->service()->redeem(
            $request->input('coupons'),
            $request->input('tokens'),
            (string) $request->input('amount'),
            $this->resolveDevice($request),
            $request->user(),
            $request->ip(),
            $request->input('location_id'),
            $request->filled('dispenser') ? (int) $request->input('dispenser') : null,
            $request->header('Idempotency-Key'),
        ));
    }

    public function topUp(TopUpRequest $request): JsonResponse
    {
        return response()->json($this->service()->topUp(
            $request->input('coupon_number'),
            $request->input('coupon_token'),
            (string) $request->input('amount'),
            $this->resolveDevice($request),
            $request->user(),
            $request->ip(),
            true,
            null,
            $request->input('location_id'),
        ));
    }

    public function restore(RestoreRequest $request): JsonResponse
    {
        $result = $this->service()->restore(
            $request->input('coupon_number'),
            (string) $request->input('coupon_amount'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
        );

        // 202: queda pendiente de que otro administrador la apruebe en el panel
        return response()->json($result, ($result['status'] ?? null) === 'pending_approval' ? 202 : 200);
    }

    /** Terminal de la petición: lo deja resuelto el middleware `coupon.device` (ver DeviceBinding). */
    protected function resolveDevice(Request $request): ?Device
    {
        if ($request->attributes->has(EnsureCouponDevice::ATTRIBUTE)) {
            return $request->attributes->get(EnsureCouponDevice::ATTRIBUTE);
        }
        $user = $request->user();

        return DeviceBinding::resolve($request, $user instanceof User ? $user : null);
    }
}
