<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El dashboard pasa a exigir `dashboard.view`. Para no quitar el acceso a
 * nadie al desplegar, se otorga a todos los roles admin existentes; luego
 * puede retirarse por rol desde la matriz RBAC.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('dashboard.view', 'admin');

        Role::query()->where('guard_name', 'admin')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'dashboard.view')->where('guard_name', 'admin')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
