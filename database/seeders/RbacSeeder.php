<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Support\AdminRbac;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos y roles base del panel Coupify. Idempotente: no borra datos existentes.
 *
 * - super-admin: todos los permisos.
 * - ops-manager: ver/editar/eliminar las pantallas operativas (config/admin_rbac.php).
 * - ops-viewer: solo lectura en las mismas pantallas.
 *
 * Primer administrador: define ADMIN_EMAIL y ADMIN_PASSWORD en el entorno antes de
 * sembrar, o usa `php artisan admin:create`. No hay credenciales por defecto.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = AdminRbac::allCrudPermissionNames();
        foreach ($all as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'admin']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'admin']);
        $superAdmin->syncPermissions($all);

        $manager = [];
        $viewer = [];
        foreach (config('admin_rbac.ops_screen_keys', []) as $screenKey) {
            $p = AdminRbac::permissionsForScreen($screenKey);
            array_push($manager, $p['view'], $p['edit'], $p['delete']);
            $viewer[] = $p['view'];
        }

        foreach (['audit_log.view'] as $extra) {
            Permission::firstOrCreate(['name' => $extra, 'guard_name' => 'admin']);
            $manager[] = $extra;
            $viewer[] = $extra;
        }

        Role::firstOrCreate(['name' => 'ops-manager', 'guard_name' => 'admin'])->syncPermissions($manager);
        Role::firstOrCreate(['name' => 'ops-viewer', 'guard_name' => 'admin'])->syncPermissions($viewer);

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if ($email && $password && ! AdminUser::query()->where('email', strtolower($email))->exists()) {
            AdminUser::create([
                'name' => env('ADMIN_NAME', 'Administrador'),
                'email' => strtolower($email),
                'password' => Hash::make($password),
                'is_active' => true,
            ])->assignRole($superAdmin);
        }
    }
}
