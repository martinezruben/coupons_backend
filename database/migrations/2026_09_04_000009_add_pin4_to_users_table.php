<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN de 4 dígitos independiente para el acceso rápido del usuario POS
 * (adicional al login usuario/contraseña). Se guarda como SHA-384 hex
 * para verificación offline en el dispositivo, igual que pin_sha384.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin4_sha384', 96)->nullable()->after('pin_sha384');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin4_sha384');
        });
    }
};
