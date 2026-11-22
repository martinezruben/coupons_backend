<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Permiso para ver el cupón impreso (QR / código de barras); no se asigna a ningún rol: solo el super-admin lo tiene. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            foreach (['coupons.view_printed', 'coupon_barcodes.view_printed'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
