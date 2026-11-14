<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\RoleLimit;
use App\Services\AdminUserGuard;
use App\Support\AdminRbac;
use App\Support\RoleLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'limits' => RoleLimit::query()->where('role_id', $role->getKey())->first(),
            'accounts' => AdminUser::query()->role($role->name)->orderBy('name')->get(),
            'candidates' => AdminUser::query()->where('is_active', true)->whereDoesntHave('roles', fn ($q) => $q->whereKey($role->getKey()))->orderBy('name')->get(['id', 'name', 'email']),
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
            'limits' => ['nullable', 'array'],
            'limits.max_activation_size' => ['nullable', 'integer', 'min:1', 'max:'.config('coupons.max_activation_size')],
            'limits.max_batch_quantity' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'limits.max_batch_value' => ['nullable', 'numeric', 'min:0.01', 'max:99999999999.99', 'decimal:0,2'],
        ], [], [
            'limits.max_activation_size' => 'máximo de cupones por activación',
            'limits.max_batch_quantity' => 'máximo de cupones por lote',
            'limits.max_batch_value' => 'valor máximo por lote',
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

        // Límites del rol (vacío = sin límite propio)
        $new = collect(RoleLimits::KEYS)->mapWithKeys(fn (string $k) => [$k => ($validated['limits'][$k] ?? null) === null || ($validated['limits'][$k] ?? '') === '' ? null : $validated['limits'][$k]])->all();
        $row = RoleLimit::query()->firstOrNew(['role_id' => $role->getKey()]);
        $old = collect(RoleLimits::KEYS)->mapWithKeys(fn (string $k) => [$k => $row->{$k}])->all();
        $row->fill($new)->save();
        foreach ($new as $key => $value) {
            if ((string) ($old[$key] ?? '') !== (string) ($value ?? '')) {
                $changes['limit_'.$key] = [(string) ($old[$key] ?? 'sin límite'), (string) ($value ?? 'sin límite')];
            }
        }

        if ($changes !== []) {
            AdminAuditLog::record('updated', 'Role', (string) $role->getKey(), $changes);
        }

        return redirect()
            ->route('admin.rbac.matrix.edit', $role)
            ->with('status', 'Permisos del rol actualizados.');
    }

    /** Da el rol a una cuenta administradora (desde «Cuentas con este rol»). */
    public function addAccount(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeMatrix();
        abort_unless($role->guard_name === 'admin', 404);
        abort_if(RoleManagementController::isProtected($role), 403);
        $data = $request->validate(['admin_user_id' => ['required', 'integer', Rule::exists('admin_users', 'id')]], [], ['admin_user_id' => 'cuenta']);

        $admin = AdminUser::findOrFail($data['admin_user_id']);
        if (! $admin->hasRole($role->name)) {
            $admin->assignRole($role);
            AdminAuditLog::record('updated', 'AdminUser', (string) $admin->getKey(), ['roles' => ['+ '.$role->name, 'asignado desde la pantalla del rol']]);
        }

        return redirect()->route('admin.rbac.matrix.edit', $role)->with('status', "{$admin->name} ahora tiene el rol «{$role->name}».");
    }

    /** Quita el rol a una cuenta; respeta las protecciones del último super-admin y de uno mismo. */
    public function removeAccount(Request $request, Role $role, AdminUser $adminUser): RedirectResponse
    {
        $this->authorizeMatrix();
        abort_unless($role->guard_name === 'admin', 404);
        abort_if(RoleManagementController::isProtected($role), 403);

        if ($adminUser->hasRole($role->name)) {
            $remaining = $adminUser->roles->pluck('name')->reject(fn ($n) => $n === $role->name)->values()->all();
            try {
                AdminUserGuard::assertChangeAllowed($request->user('admin'), $adminUser, $remaining, (bool) $adminUser->is_active);
            } catch (ValidationException $e) {
                return redirect()->route('admin.rbac.matrix.edit', $role)->withErrors($e->errors());
            }
            $adminUser->removeRole($role);
            AdminAuditLog::record('updated', 'AdminUser', (string) $adminUser->getKey(), ['roles' => ['- '.$role->name, 'quitado desde la pantalla del rol']]);
        }

        return redirect()->route('admin.rbac.matrix.edit', $role)->with('status', "{$adminUser->name} ya no tiene el rol «{$role->name}».");
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
