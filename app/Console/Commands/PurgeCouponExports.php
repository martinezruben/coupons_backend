<?php

namespace App\Console\Commands;

use App\Models\CouponBatch;
use App\Services\CouponBatchGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Borra los CSV de lotes vencidos: el token en claro no debe quedar guardado más de lo necesario. */
class PurgeCouponExports extends Command
{
    protected $signature = 'coupons:purge-exports';

    protected $description = 'Elimina los archivos CSV de lotes cuyo plazo de descarga venció';

    public function handle(): int
    {
        $purged = 0;
        CouponBatch::query()->whereNotNull('export_path')->where('export_expires_at', '<=', now())->each(function (CouponBatch $batch) use (&$purged) {
            Storage::disk('local')->delete($batch->export_path);
            $batch->forceFill(['export_path' => null])->save();
            CouponBatchGenerator::audit($batch, 'export_expired', null, null, '', '', 'El archivo del lote venció y se eliminó sin descargarse');
            $purged++;
        });
        $this->info("Archivos eliminados: {$purged}");

        return self::SUCCESS;
    }
}
