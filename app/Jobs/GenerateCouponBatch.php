<?php

namespace App\Jobs;

use App\Models\CouponBatch;
use App\Services\CouponBatchGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Genera los cupones de un lote en segundo plano (hasta 20 000). */
class GenerateCouponBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $batchId) {}

    public function handle(CouponBatchGenerator $generator): void
    {
        $batch = CouponBatch::find($this->batchId);

        if ($batch && $batch->status === CouponBatch::STATUS_GENERATING) {
            $generator->generate($batch);
        }
    }
}
