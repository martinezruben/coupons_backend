<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminRbac;
use App\Support\LocationMapSyncPins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pins del mapa de localidades en JSON, para refrescar el estado sin recargar la página.
 * Respeta los filtros de la tabla (misma query string).
 */
class LocationMapStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('admin');
        $p = AdminRbac::permissionsForScreen('locations');
        abort_unless($user && $user->can($p['view']), 403);

        return response()->json(LocationMapSyncPins::forRequest($request, $user->can($p['edit'])));
    }
}
