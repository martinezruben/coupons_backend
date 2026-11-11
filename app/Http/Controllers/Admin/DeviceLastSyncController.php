<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeviceLastSyncController extends Controller
{
    public function store(Request $request, Device $device): RedirectResponse
    {
        $user = $request->user('admin');
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('devices');
        abort_unless($user->can($p['edit']), 403);

        $device->update(['last_sync_at' => null]);

        return back()->with('status', 'Última sincronización restablecida.');
    }
}
