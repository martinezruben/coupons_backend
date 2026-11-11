<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('app_version', 30)->nullable()->after('device_fingerprint');
            $table->timestamp('app_version_seen_at')->nullable()->after('app_version');
        });

        Schema::table('system_parameters', function (Blueprint $table) {
            $table->string('min_app_version', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'app_version_seen_at']);
        });

        Schema::table('system_parameters', function (Blueprint $table) {
            $table->dropColumn('min_app_version');
        });
    }
};
