<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importa los datos de la API anterior (couponsapi) desde una conexión de solo lectura:
 * cupones QR y de barras, transacciones y usuarios. Es idempotente: no duplica filas y por
 * defecto no pisa saldos ya existentes (usar --sync-balances en la ventana de corte).
 */
class ImportLegacyCoupons extends Command
{
    protected $signature = 'coupons:import-legacy
        {--connection=legacy : Conexión de origen (config/database.php)}
        {--chunk=500 : Filas por lote}
        {--sync-balances : Actualiza coupon_balance de cupones ya importados con el saldo del origen}
        {--dry-run : Solo cuenta y valida, no escribe}';

    protected $description = 'Importa cupones, transacciones y usuarios desde la base de datos de couponsapi';

    private const COUPON_COLUMNS = [
        'coupon_id', 'coupon_number', 'coupon_token', 'coupon_active', 'coupon_date',
        'coupon_expire_date', 'coupon_amount', 'coupon_balance',
    ];

    public function handle(): int
    {
        $name = (string) $this->option('connection');
        if ($name === config('database.default')) {
            $this->error('La conexión de origen no puede ser la conexión por defecto de esta aplicación.');

            return self::FAILURE;
        }

        try {
            $source = DB::connection($name);
            $source->getPdo();
        } catch (\Throwable $e) {
            $this->error("No se pudo conectar a «{$name}»: ".$e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN: no se escribirá nada.' : 'Importando…');

        $rows = [];
        foreach (['coupons', 'coupons_codbar'] as $table) {
            $rows[] = $this->importCoupons($source, $name, $table, $dry);
        }
        $rows[] = $this->importTransactions($source, $name, $dry);
        $rows[] = $this->importUsers($source, $name, $dry);

        $this->table(['Entidad', 'Origen', 'Importadas', 'Omitidas/existentes'], $rows);

        if (! $dry) {
            foreach (['coupons', 'coupons_codbar'] as $table) {
                if (Schema::connection($name)->hasTable($table)) {
                    $this->line(sprintf(
                        'Saldo total %s: origen %s | destino %s',
                        $table,
                        number_format((float) $source->table($table)->sum('coupon_balance'), 2, '.', ''),
                        number_format((float) DB::table($table)->sum('coupon_balance'), 2, '.', ''),
                    ));
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string|int> */
    private function importCoupons($source, string $name, string $table, bool $dry): array
    {
        if (! Schema::connection($name)->hasTable($table)) {
            return [$table, 'sin tabla', 0, 0];
        }

        $cols = array_values(array_intersect(self::COUPON_COLUMNS, Schema::connection($name)->getColumnListing($table)));
        if ($table === 'coupons_codbar') {
            $cols = array_values(array_diff($cols, ['coupon_token']));
        }
        $total = $imported = 0;

        $source->table($table)->orderBy('coupon_id')->chunk((int) $this->option('chunk'), function ($chunk) use ($table, $cols, $dry, &$total, &$imported) {
            $data = $chunk->map(fn ($r) => collect((array) $r)->only($cols)->all())->all();
            $total += count($data);
            if ($dry) {
                return;
            }
            $imported += DB::table($table)->insertOrIgnore($data);

            if ($this->option('sync-balances')) {
                foreach ($data as $row) {
                    DB::table($table)->where('coupon_id', $row['coupon_id'])->update(['coupon_balance' => $row['coupon_balance']]);
                }
            }
        });

        return [$table, $total, $imported, $dry ? '—' : $total - $imported];
    }

    /** @return list<string|int> */
    private function importTransactions($source, string $name, bool $dry): array
    {
        if (! Schema::connection($name)->hasTable('transactions')) {
            return ['transactions → coupon_transactions', 'sin tabla', 0, 0];
        }

        $total = $imported = 0;
        $source->table('transactions')->orderBy('transaction_id')->chunk((int) $this->option('chunk'), function ($chunk) use ($dry, &$total, &$imported) {
            $data = $chunk->map(fn ($r) => [
                'transaction_id' => $r->transaction_id,
                'coupon_id' => $r->coupon_id,
                'coupon_number' => $r->coupon_number,
                'transaction_amount' => $r->transaction_amount,
                'coupon_type' => $r->coupon_type ?? 'QRCODE',
                'legacy_device_id' => $r->device_id ?? null,
                'legacy_site_id' => $r->site_id ?? null,
                'created_at' => $r->created_at ?? null,
                'updated_at' => $r->updated_at ?? null,
            ])->all();
            $total += count($data);
            if (! $dry) {
                $imported += DB::table('coupon_transactions')->insertOrIgnore($data);
            }
        });

        return ['transactions → coupon_transactions', $total, $imported, $dry ? '—' : $total - $imported];
    }

    /** @return list<string|int> */
    private function importUsers($source, string $name, bool $dry): array
    {
        if (! Schema::connection($name)->hasTable('users')) {
            return ['users', 'sin tabla', 0, 0];
        }

        $total = $imported = $skipped = 0;
        $source->table('users')->orderBy('id')->each(function ($r) use ($dry, &$total, &$imported, &$skipped) {
            $total++;
            $username = mb_strtolower(trim((string) ($r->email ?? $r->username ?? '')));
            $already = $username === '' || User::withTrashed()->where(fn ($q) => $q
                ->where('legacy_user_id', $r->id)->orWhereRaw('LOWER(username) = ?', [$username]))->exists();

            if ($already) {
                $skipped++;

                return;
            }
            if ($dry) {
                return;
            }

            $isAdmin = (int) ($r->admin ?? 0) === 1 || strtolower((string) ($r->role ?? '')) === 'admin';
            // El hash bcrypt se copia tal cual (el mutador de User no lo vuelve a hashear).
            User::create([
                'username' => $username,
                'full_name' => $r->name ?? $username,
                'password' => $r->password,
                'role' => $isAdmin ? 'ADMIN' : 'CASHIER',
                'is_active' => true,
                'legacy_user_id' => $r->id,
            ]);
            $imported++;
        });

        return ['users', $total, $imported, $skipped];
    }
}
