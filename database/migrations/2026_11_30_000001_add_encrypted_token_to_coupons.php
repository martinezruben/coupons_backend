<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coupons') && ! Schema::hasColumn('coupons', 'coupon_token_enc')) {
            Schema::table('coupons', function (Blueprint $table) {
                // Token QR cifrado con la clave de la aplicación (APP_KEY): permite volver a dibujar el QR impreso.
                // La validación del cobro sigue usando solo el hash de `coupon_token`.
                $table->text('coupon_token_enc')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
