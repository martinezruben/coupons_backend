<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            // Hash PBKDF2 de la clave de administración de la app (cambiar la URL del API, salir del kiosco). Nunca en claro.
            if (! Schema::hasColumn('system_parameters', 'app_unlock_hash')) {
                $table->text('app_unlock_hash')->nullable();
            }
            if (! Schema::hasColumn('system_parameters', 'app_unlock_set_at')) {
                $table->timestamp('app_unlock_set_at')->nullable();
            }
            // Datos del recibo comunes a todas las localidades
            if (! Schema::hasColumn('system_parameters', 'receipt_company_name')) {
                $table->string('receipt_company_name', 120)->nullable();
            }
            if (! Schema::hasColumn('system_parameters', 'receipt_footer')) {
                $table->string('receipt_footer', 255)->nullable();
            }
        });

        if (! Schema::hasColumn('locations', 'phone')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('phone', 40)->nullable()->after('address');
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
