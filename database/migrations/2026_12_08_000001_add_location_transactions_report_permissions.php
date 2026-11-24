<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Permisos del reporte «Transacciones por localidad» (sin asignar a ningún rol: solo el super-admin los tiene). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            foreach (['coupon_location_transactions.view', 'coupon_location_transactions.export'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
