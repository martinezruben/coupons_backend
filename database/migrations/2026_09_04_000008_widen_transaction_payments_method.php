<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transaction_payments.payment_method pasa de ENUM a string(30) para aceptar
 * IDs de métodos de pago creados en el panel (ej. "pm-9f2c…"), no solo las
 * 4 categorías originales. En sqlite (tests) la tabla se crea ya ensanchada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('transaction_payments')) {
            return;
        }

        DB::statement('ALTER TABLE transaction_payments MODIFY COLUMN payment_method VARCHAR(30) NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('transaction_payments')) {
            return;
        }

        // El rollback falla si existen métodos fuera del enum original; se asume que no hay
        DB::statement("ALTER TABLE transaction_payments MODIFY COLUMN payment_method ENUM('CASH','CARD','TRANSFER','OTHER') NOT NULL");
    }
};
