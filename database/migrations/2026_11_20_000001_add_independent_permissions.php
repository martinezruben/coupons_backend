<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos independientes nuevos (ver historial completo de un cupón, exportar CSV). Quien ya podía ver la
 * pantalla —por rol o por permiso directo— los recibe para no perder lo que hacía hasta ahora.
 */
return new class extends Migration
{
    private const PAIRS = [
        'coupons.view' => 'coupons.detail',
        'coupon_barcodes.view' => 'coupon_barcodes.detail',
        'audit_log.view' => 'audit_log.export',
        'coupon_reports.view' => 'coupon_reports.export',
        'coupon_batch_report.view' => 'coupon_batch_report.export',
        'coupon_customer_report.view' => 'coupon_customer_report.export',
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('permissions')) {
            return;
        }

        foreach (self::PAIRS as $view => $new) {
            $viewId = DB::table('permissions')->where('name', $view)->where('guard_name', 'admin')->value('id');
            if (! $viewId) {
                continue; // nadie tiene el permiso de ver: no hay nada que conservar
            }
            $newId = DB::table('permissions')->where('name', $new)->where('guard_name', 'admin')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $new, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);

            foreach (DB::table('role_has_permissions')->where('permission_id', $viewId)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $newId, 'role_id' => $roleId]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $viewId)->get() as $row) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $newId, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible a propósito: no se sabe quién los tenía antes.
    }
};
