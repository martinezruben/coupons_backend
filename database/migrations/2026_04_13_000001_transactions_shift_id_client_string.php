<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El POS envía un identificador de turno libre (p. ej. "T1"); ya no es FK a `shifts`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            try {
                Schema::table('transactions', function (Blueprint $table) {
                    $table->dropForeign(['shift_id']);
                });
            } catch (Throwable) {
            }
            DB::statement('ALTER TABLE transactions MODIFY COLUMN shift_id VARCHAR(100) NULL');

            return;
        }

        if ($driver === 'sqlite') {
            try {
                Schema::table('transactions', function (Blueprint $table) {
                    $table->dropForeign(['shift_id']);
                });
            } catch (Throwable) {
            }

            try {
                Schema::table('transactions', function (Blueprint $table) {
                    $table->string('shift_id', 100)->nullable()->change();
                });
            } catch (Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE transactions MODIFY COLUMN shift_id CHAR(36) NULL');
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreign('shift_id')->references('id')->on('shifts');
            });

            return;
        }

        if ($driver === 'sqlite') {
            try {
                Schema::table('transactions', function (Blueprint $table) {
                    $table->uuid('shift_id')->nullable()->change();
                });
            } catch (Throwable) {
            }
        }
    }
};
