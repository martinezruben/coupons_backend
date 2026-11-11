<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL: primero ampliar ENUM para incluir PAID; luego COMPLETED → PAID; por último quitar COMPLETED del tipo.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('PENDING', 'COMPLETED', 'VOIDED', 'PAID') NOT NULL DEFAULT 'COMPLETED'");
            DB::table('transactions')->where('status', 'COMPLETED')->update(['status' => 'PAID']);
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('PENDING', 'PAID', 'VOIDED') NOT NULL DEFAULT 'PAID'");

            return;
        }

        DB::table('transactions')->where('status', 'COMPLETED')->update(['status' => 'PAID']);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('PENDING', 'COMPLETED', 'VOIDED', 'PAID') NOT NULL DEFAULT 'PAID'");
            DB::table('transactions')->where('status', 'PAID')->update(['status' => 'COMPLETED']);
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('PENDING', 'COMPLETED', 'VOIDED') NOT NULL DEFAULT 'COMPLETED'");

            return;
        }

        DB::table('transactions')->where('status', 'PAID')->update(['status' => 'COMPLETED']);
    }
};
