<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el estado INACTIVE al ENUM de licenses.status.
 * Laravel no expone ALTER COLUMN para enum en MySQL 8,
 * así que se usa el SQL nativo con MODIFY COLUMN manteniendo
 * los valores existentes + INACTIVE.
 *
 * SQLite (tests) no soporta MODIFY, y sí valida el CHECK que Laravel crea
 * para el enum, así que ahí se reconstruye la columna con ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('licenses', function (Blueprint $table) {
                $table->enum('status', ['ACTIVE', 'INACTIVE', 'EXPIRED', 'REVOKED'])->default('ACTIVE')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE licenses MODIFY COLUMN status ENUM('ACTIVE','INACTIVE','EXPIRED','REVOKED') NOT NULL DEFAULT 'ACTIVE'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('licenses', function (Blueprint $table) {
                $table->enum('status', ['ACTIVE', 'EXPIRED', 'REVOKED'])->default('ACTIVE')->change();
            });

            return;
        }

        // Revertir: quitar INACTIVE (MySQL no permite si hay rows con ese valor;
        // se asume que no existen al rollback)
        DB::statement(
            "ALTER TABLE licenses MODIFY COLUMN status ENUM('ACTIVE','EXPIRED','REVOKED') NOT NULL DEFAULT 'ACTIVE'"
        );
    }
};
