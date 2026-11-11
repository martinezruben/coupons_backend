<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las sesiones del panel iniciadas antes de esta fecha dejan de valer ("cerrar sesiones")
        Schema::table('admin_users', function (Blueprint $table) {
            $table->timestamp('sessions_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('sessions_revoked_at');
        });
    }
};
