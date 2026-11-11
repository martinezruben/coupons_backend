<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class RoleRbacMatrixController extends Controller
{
    public function index(): RedirectResponse
    {
        $this->authorizeMatrix();

        // Primer rol editable (el de super-admin tiene todo y no se modifica); sin roles, a crearlos
        $role = Role::query()
            ->where('guard_name', 'admin')
            ->whereNotIn('name', config('admin.super_admin_roles', []))
            ->orderBy('name')
            ->first();

        if (! $role) {
            return redirect()->route('admin.roles.index')->with('status', 'Aún no hay roles editables: crea uno.');
        }

        return redirect()->route('admin.rbac.matrix.edit', $role);
    }

    public function edit(Role $role): Response
    {
        $this->authorizeMatrix();
        abort_unless($role->guard_name === 'admin', 404);
        abort_if(RoleManagementController::isProtected($role), 403, 'El rol de super-admin tiene todos los permisos y no se modifica.');

        $this->ensureManagedPermissionsExist();

        $role->load('permissions');
        $assigned = $role->permissions->pluck('name')->all();

        $roles = Role::query()
            ->where('guard_name', 'admin')
            ->whereNotIn('name', config('admin.super_admin_roles', []))
            ->orderBy('name')
            ->get();

        return response()->view('admin.rbac.matrix', [
            'role' => $role,
            'roles' => $roles,
            'screenGroups' => $this->screensGroupedByNavSection(),
            'assigned' => $assigned,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeMatrix();
        abort_unless($role->guard_name === 'admin', 404);
        abort_if(RoleManagementController::isProtected($role), 403, 'El rol de super-admin tiene todos los permisos y no se modifica.');

        $this->ensureAllCrudPermissionsExist();

        $managedFlat = AdminRbac::allManagedPermissionNames();

        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($managedFlat)],
        ]);

        $incoming = array_values(array_unique($validated['permissions'] ?? []));

        $changes = [];

        DB::transaction(function () use ($role, $managedFlat, $incoming, &$changes): void {
            $role->refresh();
            $role->load('permissions');
            $before = $role->permissions->pluck('name')->all();
            $keep = collect($before)
                ->filter(static fn (string $n): bool => ! in_array($n, $managedFlat, true))
                ->values()
                ->all();

            $role->syncPermissions(array_merge($keep, $incoming));

            // Auditoría manual: syncPermissions toca la tabla pivote, no el modelo
            $after = $role->permissions->pluck('name')->all();
            $added = array_values(array_diff($incoming, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added !== [] || $removed !== []) {
                $changes['permissions'] = [
                    array_map(static fn (string $n): array => ['action' => 'added', 'name' => $n], $added),
                    array_map(static fn (string $n): array => ['action' => 'removed', 'name' => $n], $removed),
                ];
            }
        });

        if ($changes !== []) {
            AdminAuditLog::record('updated', 'Role', (string) $role->getKey(), $changes);
        }

        return redirect()
            ->route('admin.rbac.matrix.edit', $role)
            ->with('status', 'Permisos del rol actualizados.');
    }

    /**
     * Pantallas de la matriz agrupadas por sección del menú (mismo orden que
     * el sidebar); pantallas sin grupo van al final en «Otras pantallas».
     *
     * @return list<array{label: string, screens: list<array{key: string, label: string, readonly: bool}>}>
     */
    private function screensGroupedByNavSection(): array
    {
        $managed = collect(AdminRbac::managedScreens())->keyBy('key');
        $groups = [];
        $grouped = [];

        foreach (config('admin_nav_groups', []) as $group) {
            $screens = [];
            foreach ($group['screens'] ?? [] as $key) {
                if ($managed->has($key)) {
                    $screens[] = $managed->get($key);
                    $grouped[] = $key;
                }
            }
            if ($screens !== []) {
                $groups[] = ['label' => $group['label'], 'screens' => $screens];
            }
        }

        $others = $managed->except($grouped)->values()->all();
        if ($others !== []) {
            $groups[] = ['label' => 'Otras pantallas', 'screens' => $others];
        }

        return $groups;
    }

    private function authorizeMatrix(): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        abort_unless(AdminRbac::isSuperAdmin($user), 403);
    }

    private function ensureManagedPermissionsExist(): void
    {
        foreach (AdminRbac::allManagedPermissionNames() as $name) {
            Permission::findOrCreate($name, 'admin');
        }
    }

    private function ensureAllCrudPermissionsExist(): void
    {
        foreach (AdminRbac::allCrudPermissionNames() as $name) {
            Permission::findOrCreate($name, 'admin');
        }
    }
}
