<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** «Activar/desactivar» se separa en dos permisos: quien tenía el botón conserva ambos. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('permissions')) {
            return;
        }

        foreach (['coupons', 'coupon_barcodes'] as $resource) {
            $old = DB::table('permissions')->where('name', "$resource.toggle")->where('guard_name', 'admin')->value('id');
            if (! $old) {
                continue;
            }
            foreach (['activate', 'deactivate'] as $action) {
                $new = DB::table('permissions')->where('name', "$resource.$action")->where('guard_name', 'admin')->value('id')
                    ?? DB::table('permissions')->insertGetId(['name' => "$resource.$action", 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
                foreach (DB::table('role_has_permissions')->where('permission_id', $old)->pluck('role_id') as $roleId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $new, 'role_id' => $roleId]);
                }
                foreach (DB::table('model_has_permissions')->where('permission_id', $old)->get() as $row) {
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $new, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
                }
            }
            DB::table('role_has_permissions')->where('permission_id', $old)->delete();
            DB::table('model_has_permissions')->where('permission_id', $old)->delete();
            DB::table('permissions')->where('id', $old)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible a propósito.
    }
};
