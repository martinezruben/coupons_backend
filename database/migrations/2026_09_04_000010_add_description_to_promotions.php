<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descripción legible de la promoción que viaja al POS para que el operario
 * entienda cómo aplica (ej. 2x1: dos unidades del mismo producto al mismo
 * precio, pagando una; o al precio fijado en la promoción).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
