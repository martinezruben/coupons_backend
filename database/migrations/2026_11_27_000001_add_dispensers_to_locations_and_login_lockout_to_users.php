<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'dispensers')) {
            Schema::table('locations', function (Blueprint $table) {
                // Número de dispensadores de la localidad (1 a 20): lo valida el panel y baja a la app por la API
                $table->unsignedTinyInteger('dispensers')->default(1)->after('address');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            }
            if (! Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
