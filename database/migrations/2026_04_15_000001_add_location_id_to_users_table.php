<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('location_id')->nullable()->after('is_active');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });

        if (Schema::hasTable('user_locations')) {
            DB::statement('UPDATE users SET location_id = (
                SELECT MIN(ul.location_id) FROM user_locations ul WHERE ul.user_id = users.id
            ) WHERE EXISTS (SELECT 1 FROM user_locations ul2 WHERE ul2.user_id = users.id)');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
