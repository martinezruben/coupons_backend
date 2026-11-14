<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\RoleLimit;
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

        // Cupones: el gerente de operaciones puede ver y gestionar; ajustar saldo y revertir
        // cobros son acciones sensibles que solo recibe el super-admin (o un rol a medida).
        foreach (['coupons', 'coupon-barcodes'] as $screenKey) {
            $r = AdminRbac::resourceKey($screenKey);
            array_push($manager, "$r.view", "$r.toggle");
            $viewer[] = "$r.view";
        }
        // Reportes y reglas de recarga: ver. Editar reglas (crea condiciones de dinero) es del super-admin.
        // Lotes, activaciones y clientes: ver. Generar, descargar, activar y anular se asignan por rol (fase de permisos).
        foreach (['coupon-transactions', 'coupon-audit-logs', 'coupon-reports', 'coupon-batch-report', 'coupon-customer-report', 'topup-rules', 'coupon-prefixes', 'coupon-batches', 'coupon-activations', 'customers'] as $screenKey) {
            $r = AdminRbac::resourceKey($screenKey);
            $manager[] = "$r.view";
            $viewer[] = "$r.view";
        }

        foreach (['audit_log.view'] as $extra) {
            Permission::firstOrCreate(['name' => $extra, 'guard_name' => 'admin']);
            $manager[] = $extra;
            $viewer[] = $extra;
        }

        Role::firstOrCreate(['name' => 'ops-manager', 'guard_name' => 'admin'])->syncPermissions($manager);
        Role::firstOrCreate(['name' => 'ops-viewer', 'guard_name' => 'admin'])->syncPermissions($viewer);

        $this->seedCouponProfiles();

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

    /**
     * Perfiles de partida para el flujo de lotes. Solo se crean si no existen: si alguien ya los ajustó,
     * volver a sembrar no pisa sus cambios.
     */
    private function seedCouponProfiles(): void
    {
        $views = ['coupon_batches.view', 'coupon_activations.view', 'coupons.view', 'coupon_barcodes.view'];

        $profiles = [
            // Genera lotes y descarga el archivo; no activa (separación de funciones)
            'generador' => ['permissions' => ['coupon_batches.view', 'coupon_batches.generate', 'coupon_batches.download', 'coupons.view', 'coupon_barcodes.view'], 'limits' => null],
            // Asigna y activa cualquier selección, incluido el lote completo, y programa
            'operador' => ['permissions' => [...$views, 'coupon_batches.assign', 'coupon_batches.activate', 'coupon_batches.activate_all', 'coupon_batches.schedule', 'coupon_batches.cancel', 'customers.view', 'customers.edit'], 'limits' => null],
            // Activa selecciones pequeñas en el momento de la venta; no puede operar el lote completo ni programar
            'ventas' => ['permissions' => [...$views, 'coupon_batches.activate', 'customers.view'], 'limits' => ['max_activation_size' => 500]],
            // Corrige: libera, cancela, anula, activa/desactiva y revierte; no genera ni ajusta saldos
            'supervisor' => ['permissions' => [...$views, 'coupon_batches.assign', 'coupon_batches.cancel', 'coupon_batches.void', 'coupons.toggle', 'coupon_barcodes.toggle', 'coupon_transactions.view', 'coupon_transactions.reverse', 'customers.view', 'coupon_reports.view', 'coupon_batch_report.view', 'coupon_customer_report.view', 'coupon_audit_logs.view'], 'limits' => null],
            // Solo lectura de todo, incluida la auditoría
            'auditor' => ['permissions' => [...$views, 'coupon_transactions.view', 'coupon_audit_logs.view', 'coupon_reports.view', 'coupon_batch_report.view', 'coupon_customer_report.view', 'customers.view', 'topup_rules.view', 'audit_log.view'], 'limits' => null],
        ];

        foreach ($profiles as $name => $profile) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'admin']);
            if (! $role->wasRecentlyCreated) {
                continue;
            }
            foreach ($profile['permissions'] as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'admin']);
            }
            $role->syncPermissions($profile['permissions']);
            if ($profile['limits']) {
                RoleLimit::query()->updateOrCreate(['role_id' => $role->getKey()], $profile['limits']);
            }
        }
    }
}
