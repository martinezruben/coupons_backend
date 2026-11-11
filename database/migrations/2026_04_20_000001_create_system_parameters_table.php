<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_parameters', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('admin_password_min_length')->default(8);
            $table->boolean('admin_password_require_uppercase')->default(true);
            $table->boolean('admin_password_require_lowercase')->default(true);
            $table->boolean('admin_password_require_digit')->default(true);
            $table->boolean('admin_password_require_symbol')->default(false);
            $table->unsignedTinyInteger('pos_password_min_length')->default(4);
            $table->boolean('pos_password_require_uppercase')->default(false);
            $table->boolean('pos_password_require_lowercase')->default(true);
            $table->boolean('pos_password_require_digit')->default(true);
            $table->boolean('pos_password_require_symbol')->default(false);
            $table->unsignedTinyInteger('admin_max_failed_login_attempts')->default(5);
            $table->unsignedSmallInteger('admin_lockout_minutes')->default(15);
            $table->timestamps();
        });

        DB::table('system_parameters')->insert([
            'admin_password_min_length' => 8,
            'admin_password_require_uppercase' => true,
            'admin_password_require_lowercase' => true,
            'admin_password_require_digit' => true,
            'admin_password_require_symbol' => false,
            'pos_password_min_length' => 4,
            'pos_password_require_uppercase' => false,
            'pos_password_require_lowercase' => true,
            'pos_password_require_digit' => true,
            'pos_password_require_symbol' => false,
            'admin_max_failed_login_attempts' => 5,
            'admin_lockout_minutes' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_parameters');
    }
};
