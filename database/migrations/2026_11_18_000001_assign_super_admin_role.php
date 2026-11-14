<?php

use App\Models\AdminUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deja al administrador actual como super-admin. Sin ese rol el menú solo muestra «Inicio», porque
 * cada opción exige permisos y una cuenta sin roles no tiene ninguno.
 *
 * - Crea el rol `super-admin` (guard `admin`) si falta.
 * - Si `ADMIN_BOOTSTRAP_EMAIL` está definido, ese administrador recibe el rol.
 * - Si no, y solo cuando ningún administrador activo lo tiene aún, lo recibe la cuenta activa más antigua.
 * - Es idempotente: no quita nada y no repite asignaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('roles') || ! DB::getSchemaBuilder()->hasTable('admin_users')) {
            return;
        }

        $roleName = (string) (config('admin.super_admin_roles')[0] ?? 'super-admin');
        $morph = (new AdminUser)->getMorphClass();

        $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'admin')->value('id');
        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId(['name' => $roleName, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        }

        $email = config('admin.bootstrap_super_admin_email');
        if ($email) {
            $target = DB::table('admin_users')->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->value('id');
        } else {
            $hasActiveHolder = DB::table('model_has_roles as m')->join('admin_users as u', 'u.id', '=', 'm.model_id')
                ->where('m.role_id', $roleId)->where('m.model_type', $morph)->where('u.is_active', true)->exists();
            $target = $hasActiveHolder ? null : DB::table('admin_users')->where('is_active', true)->orderBy('id')->value('id');
        }

        if ($target) {
            DB::table('model_has_roles')->insertOrIgnore(['role_id' => $roleId, 'model_type' => $morph, 'model_id' => $target]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible a propósito: no se sabe qué cuentas ya eran super-admin antes.
    }
};
