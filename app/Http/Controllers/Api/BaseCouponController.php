<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BalanceRequest;
use App\Http\Requests\RedeemRequest;
use App\Http\Requests\RestoreRequest;
use App\Models\Device;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseCouponController extends Controller
{
    abstract protected function service(): CouponService;

    public function balance(BalanceRequest $request): JsonResponse
    {
        return response()->json(
            $this->service()->balance($request->input('coupon_number'), $request->input('coupon_token'))
        );
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
        ));
    }

    public function restore(RestoreRequest $request): JsonResponse
    {
        return response()->json($this->service()->restore(
            $request->input('coupon_number'),
            (string) $request->input('coupon_amount'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
        ));
    }

    /**
     * Dispositivo registrado que hace la operación (cabecera Device-Fingerprint). Debe estar
     * habilitado, con localidad activa y licencia vigente. Con COUPONS_REQUIRE_DEVICE_BINDING
     * es obligatorio; si no, se registra solo cuando viene.
     */
    protected function resolveDevice(Request $request): ?Device
    {
        $fingerprint = $request->header('Device-Fingerprint');
        $device = is_string($fingerprint) && $fingerprint !== ''
            ? Device::with(['location', 'activeLicense'])->where('device_fingerprint', $fingerprint)->first()
            : null;

        $valid = $device !== null && $device->is_enabled && $device->location?->is_active && $device->activeLicense !== null;

        if (! $valid) {
            if (config('coupons.require_device_binding')) {
                throw new CouponException('Device not authorized', 403);
            }

            return null;
        }

        return $device;
    }
}
