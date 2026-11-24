<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Services\AdminMfa;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Alta de un dispositivo nuevo en una localidad. Cada alta necesita el autenticador de dos administradores
 * distintos con permiso de registro (`devices.register`): ninguna persona sola puede dar de alta un terminal.
 */
class DeviceRegistrationController extends Controller
{
    public function create(Request $request): View
    {
        $this->authorizeRegister($request);

        return view('admin.devices.register', [
            'locations' => Location::query()->visibleToLocations($request->user('admin'))->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'approvers' => $this->approvers(),
            'required' => $this->required(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->authorizeRegister($request);
        $required = $this->required();

        $rules = [
            'name' => ['nullable', 'string', 'max:100'],
            'device_fingerprint' => ['required', 'string', 'max:255', Rule::unique('devices', 'device_fingerprint')],
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->where('is_active', true)],
            'approvals' => ['required', 'array', 'size:'.$required],
        ];
        $rules['approvals.*.admin_user_id'] = ['required', 'integer', 'distinct'];
        $rules['approvals.*.code'] = ['required', 'digits:6'];
        $data = $request->validate($rules, [
            'device_fingerprint.unique' => 'Ese dispositivo ya está registrado.',
            'approvals.*.admin_user_id.distinct' => 'Elige a dos personas distintas.',
        ], ['device_fingerprint' => 'identificador del dispositivo', 'location_id' => 'localidad']);

        // Las personas que aprueban deben poder hacerlo y no estar bloqueadas por intentos fallidos
        $eligible = $this->approvers()->keyBy('id');
        $approvers = collect($data['approvals'])->map(fn ($a, $i) => ['admin' => $eligible->get((int) $a['admin_user_id']), 'code' => $a['code'], 'index' => $i]);
        foreach ($approvers as $a) {
            if ($a['admin'] === null) {
                throw ValidationException::withMessages(["approvals.{$a['index']}.admin_user_id" => 'Esa persona no puede aprobar registros de dispositivos (necesita el permiso de registro y su verificación en dos pasos activa).']);
            }
            if (AdminMfa::tooManyAttempts($a['admin'])) {
                throw ValidationException::withMessages(["approvals.{$a['index']}.code" => 'Demasiados intentos de '.$a['admin']->name.'. Espera '.AdminMfa::secondsUntilRetry($a['admin']).' segundos.']);
            }
        }

        // Se comprueban los dos códigos antes de consumir ninguno: si uno falla no se gasta el otro
        $errors = [];
        foreach ($approvers as $a) {
            if (! AdminMfa::check($a['admin'], $a['code'])) {
                AdminMfa::fail($a['admin']);
                $errors["approvals.{$a['index']}.code"] = 'Código inválido o ya usado para '.$a['admin']->name.'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        foreach ($approvers as $a) {
            AdminMfa::consume($a['admin'], $a['code']);
        }

        // Solo en las localidades que el permiso del administrador alcanza
        $location = Location::query()->visibleToLocations($actor)->findOrFail($data['location_id']);
        $device = DB::transaction(function () use ($data, $location) {
            $device = Device::create([
                'location_id' => $location->id,
                'device_fingerprint' => $data['device_fingerprint'],
                'name' => $data['name'] ?? null,
                'is_enabled' => true,
                'registered_at' => null, // se completa cuando el terminal se vincula con el código de la localidad
            ]);
            License::create([
                'device_id' => $device->id,
                'valid_from' => now(),
                'valid_to' => now()->addDays(max(1, (int) config('pos.new_license_validity_days', 365))),
                'status' => 'ACTIVE',
            ]);

            return $device;
        });

        $names = $approvers->map(fn ($a) => $a['admin']->name)->implode(' y ');
        AdminAuditLog::record('registered', 'Device', (string) $device->getKey(), [
            'localidad' => ['—', $location->name],
            'aprobado_por' => ['—', $names],
            'registrado_por' => ['—', $actor->name],
        ], extra: ['summary' => 'Registró el dispositivo «'.($device->name ?: $device->device_fingerprint)."» en {$location->name}, aprobado por {$names}"]);

        return redirect()->route('admin.screens.index', 'devices')
            ->with('status', "Dispositivo registrado en {$location->name}. Ahora vincúlalo en el terminal con el código de vinculación (icono de llave en su fila).");
    }

    /** Administradores que pueden aprobar un registro: activos, con autenticador y permiso de registro. */
    private function approvers(): Collection
    {
        $permission = AdminRbac::actionPermissions('devices')['register']['permission'];

        return AdminUser::query()->where('is_active', true)->where('totp_enabled', true)->orderBy('name')->get()
            ->filter(fn (AdminUser $u) => $u->can($permission))->values();
    }

    private function required(): int
    {
        return max(2, (int) config('admin.device_registration_approvals', 2));
    }

    private function authorizeRegister(Request $request): AdminUser
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::actionPermissions('devices')['register']['permission']), 403);

        return $admin;
    }
}
