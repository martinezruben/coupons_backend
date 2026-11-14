<?php

namespace App\Console\Commands;

use App\Support\CouponToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reemplaza los tokens QR en claro (cupones importados) por su hash. Irreversible: el valor en claro se pierde. */
class HashLegacyCouponTokens extends Command
{
    protected $signature = 'coupons:hash-tokens {--force : No pedir confirmación}';

    protected $description = 'Guarda como hash los tokens QR que aún están en claro';

    public function handle(): int
    {
        $pending = DB::table('coupons')->whereNotNull('coupon_token')->where('coupon_token', 'not like', 'h1:%')->count();
        if ($pending === 0) {
            $this->info('No hay tokens en claro.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Se convertirán {$pending} tokens a hash. No se puede deshacer. ¿Continuar?")) {
            return self::FAILURE;
        }

        $done = 0;
        DB::table('coupons')->whereNotNull('coupon_token')->where('coupon_token', 'not like', 'h1:%')->orderBy('coupon_id')
            ->chunkById(1000, function ($rows) use (&$done) {
                foreach ($rows as $row) {
                    DB::table('coupons')->where('coupon_id', $row->coupon_id)->update(['coupon_token' => CouponToken::hash($row->coupon_token)]);
                    $done++;
                }
            }, 'coupon_id');
        $this->info("Tokens convertidos: {$done}");

        return self::SUCCESS;
    }
}
