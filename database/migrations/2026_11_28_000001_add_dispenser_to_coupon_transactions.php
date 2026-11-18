<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupon_transactions', 'dispenser')) {
            Schema::table('coupon_transactions', function (Blueprint $table) {
                // Surtidor (dispensador) de la localidad donde se hizo el cobro; null si la localidad no usa surtidores
                $table->unsignedTinyInteger('dispenser')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
