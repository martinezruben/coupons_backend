<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devices', 'last_transaction_at')) {
            Schema::table('devices', function (Blueprint $table) {
                // Último cobro hecho desde el terminal: sirve para ver si las tablets realmente están vendiendo
                $table->timestamp('last_transaction_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
