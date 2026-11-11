<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Support\AppVersion;
use App\Support\DevicePairingToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Registro inicial: cabeceras `Device-Fingerprint` y `Pairing-Token` (o JSON `device_fingerprint`, `pairing_token`).
     * El código de emparejamiento se genera en Admin → Localidades (por sede); fija la localidad del alta.
     * Crea dispositivo + licencia y devuelve token para sincronización completa.
     * Si la huella ya existe (p. ej. app reinstalada), devuelve token igualmente si la licencia activa sigue vigente.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $this->mergeDevicePairingHeaders($request);

        $data = $request->validate([
            'device_fingerprint' => ['required', 'string', 'max:255'],
            'pairing_token' => ['required', 'string'],
        ]);

        $fingerprint = $data['device_fingerprint'];
        $pairingInput = $data['pairing_token'];

        $locationIdFromCode = DevicePairingToken::validateAndConsume($pairingInput);
        if ($locationIdFromCode === null) {
            return response()->json([
                'message' => 'Código de vinculación inválido o caducado.',
            ], 422);
        }

        $existing = Device::with(['location', 'activeLicense'])
            ->where('device_fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            AppVersion::record($existing, $request);

            return $this->jsonTokenForExistingDeviceAfterPairing($existing);
        }

        $location = Location::query()
            ->whereKey($locationIdFromCode)
            ->where('is_active', true)
            ->first();

        if ($location === null) {
            return response()->json([
                'message' => 'La localidad asociada al código no está disponible.',
            ], 403);
        }

        $validityDays = max(1, (int) config('pos.new_license_validity_days', 365));

        $result = DB::transaction(function () use ($fingerprint, $location, $validityDays): array {
            $device = Device::create([
                'location_id' => $location->id,
                'device_fingerprint' => $fingerprint,
                'name' => null,
                'is_enabled' => true,
                'registered_at' => now(),
            ]);

            $license = License::create([
                'device_id' => $device->id,
                'valid_from' => now(),
                'valid_to' => now()->addDays($validityDays),
                'status' => 'ACTIVE',
            ]);

            $device->tokens()->delete();
            $plainToken = $device->createToken('pos-device')->plainTextToken;

            return [
                'device_id' => $device->id,
                'token' => $plainToken,
                'license_key' => $license->license_key,
                'location_id' => $device->location_id,
                'location_name' => $location->name,
                ...$this->licenseValidityForJson($license),
            ];
        });

        AppVersion::record(Device::query()->findOrFail($result['device_id']), $request);
        unset($result['device_id']);

        return response()->json($result);
    }

    /**
     * Inicio de sesión con catálogo ya descargado: `Device-Fingerprint` + `license_key` (body o cabecera `License-Key`).
     */
    public function login(Request $request): JsonResponse
    {
        $this->mergeDeviceLoginHeaders($request);

        $data = $request->validate([
            'device_fingerprint' => ['required', 'string', 'max:255'],
            'license_key' => ['required', 'string', 'max:100'],
        ], [
            'device_fingerprint.required' => 'La huella del dispositivo es obligatoria.',
            'license_key.required' => 'El license_key es obligatorio.',
            'license_key.max' => 'El license_key no es válido.',
        ]);

        $fingerprint = $data['device_fingerprint'];
        $licenseKey = $data['license_key'];

        $device = Device::with(['location', 'licenses'])
            ->where('device_fingerprint', $fingerprint)
            ->first();

        if (! $device) {
            return response()->json([
                'message' => 'Dispositivo no registrado.',
            ], 422);
        }

        if (! $device->is_enabled) {
            return response()->json([
                'message' => 'El dispositivo está deshabilitado.',
                'code' => 'DEVICE_BLOCKED',
            ], 403);
        }

        if (! $device->location || ! $device->location->is_active) {
            return response()->json([
                'message' => 'La localidad está inactiva.',
            ], 403);
        }

        $license = $device->licenses()->where('license_key', $licenseKey)->first();
        if (! $license) {
            return response()->json([
                'message' => 'Licencia no encontrada para este dispositivo.',
            ], 422);
        }

        if (! $license->isValidForOperation()) {
            return response()->json([
                'message' => 'La licencia no está activa o ha expirado. No se permite la sincronización.',
            ], 403);
        }

        $device->tokens()->delete();
        $token = $device->createToken('pos-device')->plainTextToken;
        AppVersion::record($device, $request);

        return response()->json([
            'token' => $token,
            'license_key' => $license->license_key,
            'location_id' => $device->location_id,
            'location_name' => $device->location->name,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device instanceof Device, 404);

        $device->load('activeLicense');

        return response()->json([
            'id' => $device->id,
            'name' => $device->name,
            'device_fingerprint' => $device->device_fingerprint,
            'location_id' => $device->location_id,
            'is_enabled' => $device->is_enabled,
            'license_id' => $device->activeLicense?->id,
            'license_key' => $device->activeLicense?->license_key,
        ]);
    }

    /**
     * Re-enrolamiento: app reinstalada con la misma huella; el código de pareja sigue siendo obligatorio.
     */
    private function jsonTokenForExistingDeviceAfterPairing(Device $device): JsonResponse
    {
        if (! $device->is_enabled) {
            return response()->json([
                'message' => 'El dispositivo está deshabilitado.',
                'code' => 'DEVICE_BLOCKED',
            ], 403);
        }

        if (! $device->location || ! $device->location->is_active) {
            return response()->json([
                'message' => 'La localidad está inactiva.',
            ], 403);
        }

        $license = $device->activeLicense;
        if (! $license || ! $license->isValidForOperation()) {
            return response()->json([
                'message' => 'La licencia no está activa o ha expirado. No se permite la sincronización.',
            ], 403);
        }

        $device->forceFill(['registered_at' => now()])->save();

        $device->tokens()->delete();
        $plainToken = $device->createToken('pos-device')->plainTextToken;

        return response()->json([
            'token' => $plainToken,
            'license_key' => $license->license_key,
            'location_id' => $device->location_id,
            'location_name' => $device->location->name,
            ...$this->licenseValidityForJson($license),
        ]);
    }

    private function mergeDevicePairingHeaders(Request $request): void
    {
        $fp = $this->headerOrInput($request, 'Device-Fingerprint', 'device_fingerprint');
        $pt = $this->headerOrInput($request, 'Pairing-Token', 'pairing_token');
        if ($fp !== null) {
            $request->merge(['device_fingerprint' => $fp]);
        }
        if ($pt !== null) {
            $request->merge(['pairing_token' => $pt]);
        }
    }

    private function mergeDeviceLoginHeaders(Request $request): void
    {
        $fp = $this->headerOrInput($request, 'Device-Fingerprint', 'device_fingerprint');
        $lic = $this->headerOrInput($request, 'License-Key', 'license_key');
        if ($fp !== null) {
            $request->merge(['device_fingerprint' => $fp]);
        }
        if ($lic !== null) {
            $request->merge(['license_key' => $lic]);
        }
    }

    private function headerOrInput(Request $request, string $headerName, string $inputKey): ?string
    {
        $h = $request->header($headerName);
        if (is_string($h) && $h !== '') {
            return $h;
        }

        $v = $request->input($inputKey);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @return array{valid_from: string|null, valid_to: string|null}
     */
    private function licenseValidityForJson(License $license): array
    {
        return [
            'valid_from' => $license->valid_from?->toIso8601String(),
            'valid_to' => $license->valid_to?->toIso8601String(),
        ];
    }
}
