<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comentario completo de la acción (la columna `reason` se recorta a 255)
        Schema::table('coupon_audit_logs', function (Blueprint $table) {
            $table->text('reason_full')->nullable();
        });
        // De dónde salió la localidad de un movimiento: `request` (la envía el dispositivo Android) o `device` (la del dispositivo registrado)
        Schema::table('coupon_transactions', function (Blueprint $table) {
            $table->string('location_source', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_audit_logs', fn (Blueprint $t) => $t->dropColumn('reason_full'));
        Schema::table('coupon_transactions', fn (Blueprint $t) => $t->dropColumn('location_source'));
    }
};
