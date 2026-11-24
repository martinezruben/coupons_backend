<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminLocationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Selector de localidad de la barra superior: las pantallas del panel actúan sobre la localidad elegida. */
class LocationSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        $id = $request->validate(['location_id' => ['nullable', 'uuid']])['location_id'] ?? null;

        // Solo una localidad que su permiso alcanza; vacío = todas las suyas
        if ($id !== null && ! AdminLocationScope::permits($admin, $id)) {
            abort(403);
        }
        $id === null ? $request->session()->forget(AdminLocationScope::SESSION) : $request->session()->put(AdminLocationScope::SESSION, $id);

        return back();
    }
}
