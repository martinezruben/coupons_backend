<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN de 4 dígitos del usuario POS guardado cifrado (reversible) para poder
 * mostrárselo al administrador en la edición; el hash SHA-384 para verificación
 * offline se deriva del PIN al guardarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('pin4_enc')->nullable()->after('pin4_sha384');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin4_enc');
        });
    }
};
