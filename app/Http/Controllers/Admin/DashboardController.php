<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use Illuminate\View\View;

/**
 * Página de inicio del panel Coupify. Los indicadores de cupones se agregarán
 * cuando exista el módulo de cupones; por ahora resume la infraestructura.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard-welcome', [
            'stats' => [
                ['label' => 'Usuarios del panel', 'value' => AdminUser::query()->where('is_active', true)->count()],
                ['label' => 'Localidades activas', 'value' => Location::query()->where('is_active', true)->count()],
                ['label' => 'Dispositivos habilitados', 'value' => Device::query()->where('is_enabled', true)->count()],
                ['label' => 'Licencias activas', 'value' => License::query()->where('status', 'ACTIVE')->count()],
            ],
        ]);
    }
}
