<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceOperational
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->user();

        if (! $auth instanceof Device) {
            return response()->json(['message' => 'Invalid authentication.'], 401);
        }

        $device = Device::with(['location', 'activeLicense'])->whereKey($auth->getKey())->first();

        if (! $device) {
            return response()->json(['message' => 'Device is not registered.'], 403);
        }

        if (! $device->is_enabled) {
            return response()->json(['message' => 'Device is disabled.', 'code' => 'DEVICE_BLOCKED'], 403);
        }

        if (! $device->location || ! $device->location->is_active) {
            return response()->json(['message' => 'Location is inactive.'], 403);
        }

        if (! $device->activeLicense) {
            return response()->json(['message' => 'Device license is not active.'], 403);
        }

        AppVersion::record($device, $request);

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
