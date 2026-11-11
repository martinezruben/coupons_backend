<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('disabled_reason', 255)->nullable()->after('is_enabled');
            $table->timestamp('disabled_at')->nullable()->after('disabled_reason');
            $table->string('disabled_by', 100)->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['disabled_reason', 'disabled_at', 'disabled_by']);
        });
    }
};
