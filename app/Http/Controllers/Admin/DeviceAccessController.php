<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DeviceAccessService;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeviceAccessController extends Controller
{
    public function __construct(private readonly DeviceAccessService $access) {}

    public function block(Request $request, Device $device): RedirectResponse
    {
        $this->authorizeEdit($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Indica el motivo del bloqueo.',
            'reason.min' => 'Describe el motivo con al menos 5 caracteres.',
        ]);

        $revoked = $this->access->block($device, trim($data['reason']), $request->user('admin'));

        return back()->with('status', $revoked > 0
            ? 'Dispositivo bloqueado y sesión cerrada. El equipo lo notará en su próxima conexión.'
            : 'Dispositivo bloqueado. No tenía una sesión activa.');
    }

    public function unblock(Request $request, Device $device): RedirectResponse
    {
        $this->authorizeEdit($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Indica el motivo de la reactivación.',
            'reason.min' => 'Describe el motivo con al menos 5 caracteres.',
        ]);

        $this->access->unblock($device, trim($data['reason']), $request->user('admin'));

        return back()->with('status', 'Dispositivo reactivado. Debe iniciar sesión de nuevo desde la app.');
    }

    private function authorizeEdit(Request $request): void
    {
        $user = $request->user('admin');
        abort_unless($user && $user->can(AdminRbac::permissionsForScreen('devices')['edit']), 403);
    }
}
