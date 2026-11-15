<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Una regla de recarga puede sumar varias condiciones (localidades, días, horario y topes). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_rules', function (Blueprint $table) {
            $table->json('location_ids')->nullable();
            $table->string('time_from', 5)->nullable();   // HH:MM
            $table->string('time_to', 5)->nullable();
            $table->decimal('max_per_topup', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('topup_rules', function (Blueprint $table) {
            $table->dropColumn(['location_ids', 'time_from', 'time_to', 'max_per_topup']);
        });
    }
};
