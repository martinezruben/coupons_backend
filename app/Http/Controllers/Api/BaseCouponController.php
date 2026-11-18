<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BalanceRequest;
use App\Http\Requests\RedeemRequest;
use App\Http\Requests\RestoreRequest;
use App\Http\Requests\TopUpRequest;
use App\Models\Device;
use App\Services\CouponService;
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
