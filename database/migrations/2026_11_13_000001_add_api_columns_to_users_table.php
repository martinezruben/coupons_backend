<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'legacy_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                // ID numérico del usuario en la API anterior (couponsapi). La app Android lo espera como entero.
                $table->unsignedBigInteger('legacy_user_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'legacy_user_id')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('legacy_user_id'));
        }
    }
};
