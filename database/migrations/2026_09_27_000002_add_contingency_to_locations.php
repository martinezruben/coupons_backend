<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dateTime('contingency_started_at')->nullable()->after('last_sync_at');
            $table->dateTime('contingency_reminder_sent_at')->nullable()->after('contingency_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['contingency_started_at', 'contingency_reminder_sent_at']);
        });
    }
};
