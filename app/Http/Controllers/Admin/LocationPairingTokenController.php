<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Location;
use App\Support\AdminRbac;
use App\Support\DevicePairingToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationPairingTokenController extends Controller
{
    public function show(Request $request, Location $location): JsonResponse
    {
        $this->authorizePairing($request);

        $payload = DevicePairingToken::getPayloadForLocation($location->getKey());
        if ($payload === null) {
            return response()->json(['code' => null, 'expires_at' => null]);
        }

        return response()->json([
            'code' => (string) $payload['code'],
            'expires_at' => (int) $payload['expires_at'],
        ]);
    }

    public function store(Request $request, Location $location): JsonResponse
    {
        $this->authorizePairing($request);

        $validated = $request->validate([
            'action' => ['required', 'in:ensure,regenerate'],
        ]);

        if ($validated['action'] === 'ensure') {
            $existing = DevicePairingToken::getPayloadForLocation($location->getKey());
            if ($existing !== null) {
                return response()->json([
                    'code' => (string) $existing['code'],
                    'expires_at' => (int) $existing['expires_at'],
                ]);
            }
        }

        $payload = DevicePairingToken::generateNew($location->getKey());

        // El token vive en Cache (no en BD), así que el observer no lo captura: registrar manualmente
        AdminAuditLog::record('created', 'PairingToken', (string) $location->getKey(), [
            'pairing_token' => ['—', 'token regenerado · '.$location->name],
        ]);

        return response()->json([
            'code' => $payload['code'],
            'expires_at' => $payload['expires_at'],
        ]);
    }

    private function authorizePairing(Request $request): void
    {
        $user = $request->user('admin');
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('locations');
        abort_unless($user->can($p['edit']), 403);
    }
}
