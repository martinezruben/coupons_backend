<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía promotions.type con BUNDLE (lleva N paga M) en MySQL.
 * En sqlite (tests) la tabla se crea desde cero con BUNDLE incluido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('promotions')) {
            return;
        }

        DB::statement(
            "ALTER TABLE promotions MODIFY COLUMN type ENUM('PERCENT','AMOUNT','PRICE','BUNDLE') NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('promotions')) {
            return;
        }

        // MySQL no permite el cambio si existen filas BUNDLE; se asume que no hay al rollback
        DB::statement(
            "ALTER TABLE promotions MODIFY COLUMN type ENUM('PERCENT','AMOUNT','PRICE') NOT NULL"
        );
    }
};
