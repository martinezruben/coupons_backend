<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los métodos de pago personalizados viajan como su UUID (36 caracteres) en
 * transaction_payments.payment_method, que medía 30: en MySQL estricto el push
 * completo fallaba con "Data too long". SQLite (tests) no aplica el largo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE transaction_payments MODIFY COLUMN payment_method VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE transaction_payments MODIFY COLUMN payment_method VARCHAR(30) NOT NULL');
    }
};
