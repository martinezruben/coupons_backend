<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            $table->boolean('contingency_enabled')->default(true)->after('office365_scopes');
            $table->json('contingency_email_list')->nullable()->after('contingency_enabled');
            $table->integer('contingency_resend_hours')->default(8)->after('contingency_email_list');
        });
    }

    public function down(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            $table->dropColumn([
                'contingency_enabled',
                'contingency_email_list',
                'contingency_resend_hours',
            ]);
        });
    }
};
