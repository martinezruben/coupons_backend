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
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alta, renombrado y baja de roles del panel (solo super-admin). Los permisos de cada rol se
 * asignan en la matriz «Permisos por rol» (RoleRbacMatrixController).
 */
class RoleManagementController extends Controller
{
    public function index(): Response
    {
        $this->authorizeSuperAdmin();

        $usersByRole = DB::table('model_has_roles')
            ->where('model_type', AdminUser::class)
            ->selectRaw('role_id, COUNT(*) as users')
            ->groupBy('role_id')
            ->pluck('users', 'role_id');

        $roles = Role::query()
            ->where('guard_name', 'admin')
            ->withCount('permissions')
            ->orderBy('name')
            ->get()
            ->each(function (Role $role) use ($usersByRole): void {
                $role->setAttribute('users_count', (int) ($usersByRole[$role->getKey()] ?? 0));
                $role->setAttribute('is_protected', self::isProtected($role));
            });

        return response()->view('admin.roles.index', ['roles' => $roles]);
    }

    public function create(): Response
    {
        $this->authorizeSuperAdmin();

        return response()->view('admin.roles.form', [
            'role' => null,
            'copyFrom' => Role::query()->where('guard_name', 'admin')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name' => $this->nameRules(null),
            'copy_from' => ['nullable', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'admin')],
        ], $this->messages());

        $role = DB::transaction(function () use ($data): Role {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'admin']);
            if (! empty($data['copy_from'])) {
                $role->syncPermissions(Role::findById((int) $data['copy_from'], 'admin')->permissions);
            }

            return $role;
        });

        AdminAuditLog::record('created', 'Role', (string) $role->getKey(), [
            'name' => ['', $role->name],
            'permissions_copied_from' => [null, $data['copy_from'] ?? null],
        ]);

        return redirect()
            ->route('admin.rbac.matrix.edit', $role)
            ->with('status', "Rol «{$role->name}» creado. Marca ahora sus permisos.");
    }

    public function edit(Role $role): Response
    {
        $this->authorizeSuperAdmin();
        $this->assertEditable($role);

        return response()->view('admin.roles.form', ['role' => $role, 'copyFrom' => collect()]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeSuperAdmin();
        $this->assertEditable($role);

        $data = $request->validate(['name' => $this->nameRules($role)], $this->messages());
        $old = $role->name;

        if ($old !== $data['name']) {
            $role->update(['name' => $data['name']]);
            AdminAuditLog::record('updated', 'Role', (string) $role->getKey(), ['name' => [$old, $data['name']]]);
        }

        return redirect()->route('admin.roles.index')->with('status', 'Rol actualizado.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorizeSuperAdmin();
        $this->assertEditable($role);

        $users = DB::table('model_has_roles')->where('role_id', $role->getKey())->where('model_type', AdminUser::class)->count();
        if ($users > 0) {
            return back()->withErrors(['role' => "No se puede eliminar «{$role->name}»: tiene {$users} usuario(s) asignado(s). Cámbiales el rol primero."]);
        }

        $name = $role->name;
        $id = (string) $role->getKey();
        $role->delete();
        AdminAuditLog::record('deleted', 'Role', $id, ['name' => [$name, '']]);

        return redirect()->route('admin.roles.index')->with('status', "Rol «{$name}» eliminado.");
    }

    /** Los roles de super-admin son intocables: ni se renombran, ni se eliminan, ni se les editan permisos. */
    public static function isProtected(Role $role): bool
    {
        return in_array($role->name, config('admin.super_admin_roles', []), true);
    }

    private function assertEditable(Role $role): void
    {
        abort_unless($role->guard_name === 'admin', 404);
        abort_if(self::isProtected($role), 403, 'El rol de super-admin no se puede modificar.');
    }

    /** @return list<mixed> */
    private function nameRules(?Role $role): array
    {
        $unique = Rule::unique('roles', 'name')->where('guard_name', 'admin');
        if ($role) {
            $unique = $unique->ignore($role->getKey());
        }

        return ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $unique];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.regex' => 'Usa solo minúsculas, números y guiones (p. ej. «gerente-sucursal»).',
            'name.unique' => 'Ya existe un rol con ese nombre.',
        ];
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(AdminRbac::isSuperAdmin(auth('admin')->user()), 403);
    }
}
