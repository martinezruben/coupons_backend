<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupify no usa el núcleo POS heredado (catálogo, ventas, turnos, NCF, sync).
 * Las migraciones que creaban esas tablas se eliminaron; esta migración las borra
 * en bases que ya las tenían (en una base nueva no hace nada).
 */
return new class extends Migration
{
    private const TABLES = [
        'transaction_payments',
        'transaction_items',
        'transactions',
        'shifts',
        'sync_logs',
        'sync_states',
        'promotions',
        'products',
        'subfamilies',
        'families',
        'ncf_sequences',
        'payment_methods',
    ];

    /** Prefijos de permisos `{recurso}.view|edit|delete` de las pantallas eliminadas. */
    private const PERMISSION_PREFIXES = [
        'families', 'subfamilies', 'promotions', 'products', 'shifts', 'transactions',
        'transaction_items', 'transaction_payments', 'sync_states', 'sync_logs',
        'ncf_sequences', 'ncf_report', 'payment_methods', 'transactions_report',
        'products_margin', 'products_no_movement', 'products_best_sellers',
        'payment_methods_report', 'users_performance_report', 'cierre_caja',
        'dashboard', 'dashboard_technical',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        if (Schema::hasTable('system_parameters') && Schema::hasColumn('system_parameters', 'sync_paused')) {
            Schema::table('system_parameters', fn (Blueprint $t) => $t->dropColumn('sync_paused'));
        }

        if (Schema::hasTable('permissions')) {
            $names = [];
            foreach (self::PERMISSION_PREFIXES as $prefix) {
                foreach (['view', 'edit', 'delete'] as $action) {
                    $names[] = "$prefix.$action";
                }
            }
            $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
                DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
                DB::table('permissions')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        // Irreversible: los datos del POS heredado no se restauran.
    }
};
