<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

/**
 * Permisos específicos por usuario: se suman a los de sus roles (permisos directos). Solo super-admin.
 * Cada cambio queda en la auditoría con lo agregado y lo quitado.
 */
class UserPermissionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeSuper();

        $users = AdminUser::query()->with('roles:id,name')->orderBy('name')->get();
        $direct = DB::table('model_has_permissions')->where('model_type', (new AdminUser)->getMorphClass())
            ->selectRaw('model_id, COUNT(*) as n')->groupBy('model_id')->pluck('n', 'model_id');

        return view('admin.rbac.users', ['users' => $users, 'direct' => $direct]);
    }

    public function edit(AdminUser $adminUser): View
    {
        $this->authorizeSuper();
        abort_if($adminUser->isSuperAdmin(), 403, 'Un super-admin ya tiene todos los permisos.');

        foreach (AdminRbac::allManagedPermissionNames() as $name) {
            Permission::findOrCreate($name, 'admin');
        }

        $adminUser->load('roles.permissions');
        $viaRole = [];
        foreach ($adminUser->roles as $role) {
            foreach ($role->permissions as $permission) {
                $viaRole[$permission->name][] = $role->name;
            }
        }

        // Permisos independientes: cada acción sensible se concede sola, sin depender de editar la pantalla
        $independent = [];
        foreach (app(RoleRbacMatrixController::class)->screensGroupedByNavSection() as $group) {
            foreach ($group['screens'] as $screen) {
                foreach (AdminRbac::actionPermissions($screen['key']) as $action => $info) {
                    $independent[$group['label']][] = [
                        'permission' => $info['permission'], 'label' => $info['label'], 'screen' => $screen['label'],
                        'sensitive' => in_array($action, ['adjust', 'topup', 'reverse', 'void', 'activate_all', 'generate', 'download', 'export'], true),
                    ];
                }
            }
        }

        return view('admin.rbac.user-permissions', [
            'independent' => $independent,
            'target' => $adminUser,
            'screenGroups' => app(RoleRbacMatrixController::class)->screensGroupedByNavSection(),
            'direct' => $adminUser->getDirectPermissions()->pluck('name')->all(),
            'viaRole' => $viaRole,
            'users' => AdminUser::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, AdminUser $adminUser): RedirectResponse
    {
        $this->authorizeSuper();
        abort_if($adminUser->isSuperAdmin(), 403, 'Un super-admin ya tiene todos los permisos.');

        $managed = AdminRbac::allManagedPermissionNames();
        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($managed)],
        ]);
        foreach (AdminRbac::allCrudPermissionNames() as $name) {
            Permission::findOrCreate($name, 'admin');
        }

        $incoming = array_values(array_unique($validated['permissions'] ?? []));

        $before = $adminUser->getDirectPermissions()->pluck('name')->all();
        // Solo se tocan los permisos de la matriz; otros permisos directos se conservan
        $keep = array_values(array_filter($before, fn (string $n) => ! in_array($n, $managed, true)));
        $adminUser->syncPermissions(array_merge($keep, $incoming));

        $after = $adminUser->fresh()->getDirectPermissions()->pluck('name')->all();
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== [] || $removed !== []) {
            AdminAuditLog::record('updated', 'AdminUser', (string) $adminUser->getKey(), [
                'permisos_directos' => [
                    $removed === [] ? 'sin quitar' : '− '.implode(', ', $removed),
                    $added === [] ? 'sin agregar' : '+ '.implode(', ', $added),
                ],
            ], extra: ['summary' => "Cambió los permisos específicos de {$adminUser->name} (+".count($added).' −'.count($removed).')']);
        }

        return redirect()->route('admin.rbac.users.edit', $adminUser)->with('status', 'Permisos de '.$adminUser->name.' actualizados.');
    }

    private function authorizeSuper(): void
    {
        $user = auth('admin')->user();
        abort_unless($user && AdminRbac::isSuperAdmin($user), 403);
    }
}
