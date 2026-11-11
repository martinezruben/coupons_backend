<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El dashboard se separa en comercial y técnico; el técnico exige
 * `dashboard_technical.view`. Se otorga a los roles que ya veían el
 * dashboard para que nadie pierda acceso; luego se ajusta por rol.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('dashboard_technical.view', 'admin');

        Role::query()->where('guard_name', 'admin')->get()
            ->filter(fn (Role $role) => $role->hasPermissionTo('dashboard.view', 'admin'))
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'dashboard_technical.view')->where('guard_name', 'admin')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
