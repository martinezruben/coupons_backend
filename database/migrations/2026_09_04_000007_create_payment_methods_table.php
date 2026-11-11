<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Métodos de pago gestionables desde el panel. Los 4 originales de
     * config/sync_catalog.php se siembran conservando sus IDs legacy
     * (pm-cash, pm-card…) para no romper los dispositivos ya sincronizados.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('name', 60);
            $table->enum('type', ['CASH', 'CARD', 'TRANSFER', 'OTHER']);
            $table->string('color', 20)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('payment_methods')->insert([
            ['id' => 'pm-cash', 'name' => 'Efectivo', 'type' => 'CASH', 'color' => 'emerald', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'pm-card', 'name' => 'Tarjeta', 'type' => 'CARD', 'color' => 'sky', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'pm-transfer', 'name' => 'Transferencia', 'type' => 'TRANSFER', 'color' => 'violet', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'pm-other', 'name' => 'Otro', 'type' => 'OTHER', 'color' => 'amber', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
