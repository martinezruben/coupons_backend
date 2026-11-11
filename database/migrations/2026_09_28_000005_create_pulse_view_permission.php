<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pulse pasa a exigir `pulse.view`. No se otorga a ningún rol: expone datos
 * técnicos sensibles. super-admin sigue entrando; el resto se asigna en la
 * matriz de permisos por rol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('pulse.view', 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'pulse.view')->where('guard_name', 'admin')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
