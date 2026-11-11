<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            $table->boolean('sync_paused')->default(false)->after('pos_min_special_chars');
        });

        DB::table('system_parameters')->update(['sync_paused' => false]);
    }

    public function down(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            $table->dropColumn('sync_paused');
        });
    }
};
