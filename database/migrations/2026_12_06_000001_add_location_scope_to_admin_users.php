<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Alcance por localidad de los administradores del panel: todas, o solo las que se les asignan. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admin_users', 'location_scope')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->string('location_scope', 10)->default('all');
            });
        }

        if (! Schema::hasTable('admin_user_locations')) {
            Schema::create('admin_user_locations', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_user_id');
                $table->uuid('location_id');
                $table->primary(['admin_user_id', 'location_id']);
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
