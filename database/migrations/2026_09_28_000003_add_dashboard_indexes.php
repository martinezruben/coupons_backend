<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las consultas más frecuentes del panel:
 * - dashboard y reportes sin filtro de localidad: status + occurred_at;
 * - dashboard técnico: sincronizaciones por fecha para todos los dispositivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['status', 'occurred_at'], 'transactions_status_occurred_at_index');
        });

        Schema::table('sync_logs', function (Blueprint $table): void {
            $table->index('started_at', 'sync_logs_started_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_status_occurred_at_index');
        });

        Schema::table('sync_logs', function (Blueprint $table): void {
            $table->dropIndex('sync_logs_started_at_index');
        });
    }
};
