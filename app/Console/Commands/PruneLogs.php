<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purga registros antiguos de logs operativos para que la BD no crezca sin límite.
 * Tablas: sync_logs, api_request_logs, admin_audit_logs.
 */
class PruneLogs extends Command
{
    protected $signature = 'pos:prune-logs
        {--days=90 : Antigüedad general en días}
        {--sync-days= : Días de retención de sync_logs (si no, usa --days)}
        {--api-days= : Días de retención de api_request_logs (si no, usa --days)}
        {--audit-days= : Días de retención de admin_audit_logs (si no, usa --days)}';

    protected $description = 'Elimina registros antiguos de sync_logs, api_request_logs y admin_audit_logs';

    public function handle(): int
    {
        $general = max(1, (int) $this->option('days'));

        $targets = [
            'sync_logs' => ['column' => 'started_at', 'days' => $this->option('sync-days') ?? $general],
            'api_request_logs' => ['column' => 'created_at', 'days' => $this->option('api-days') ?? $general],
            'admin_audit_logs' => ['column' => 'created_at', 'days' => $this->option('audit-days') ?? $general],
        ];

        foreach ($targets as $table => $cfg) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->warn("Tabla {$table} no existe; omitida.");

                continue;
            }
            $cutoff = now()->subDays(max(1, (int) $cfg['days']));
            $deleted = DB::table($table)->where($cfg['column'], '<', $cutoff)->delete();
            $this->info("{$table}: {$deleted} eliminados (anteriores a {$cutoff->toDateString()}).");
        }

        return self::SUCCESS;
    }
}
