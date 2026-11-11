<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El reporte «Productos sin movimiento» exige `products_no_movement.view`; se otorga a los
 * roles que ya veían «Productos más vendidos» para que nadie pierda ese bloque del menú.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('products_no_movement.view', 'admin');

        Role::query()->where('guard_name', 'admin')->get()
            ->filter(fn (Role $role) => $role->hasPermissionTo('products_best_sellers.view', 'admin'))
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'products_no_movement.view')->where('guard_name', 'admin')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
