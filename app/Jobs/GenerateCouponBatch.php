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
        // Reclamo atómico: si el job se reintenta o dos workers lo toman, solo uno genera.
        $claimed = CouponBatch::query()->whereKey($this->batchId)
            ->where('status', CouponBatch::STATUS_GENERATING)->whereNull('generation_started_at')
            ->update(['generation_started_at' => now()]);

        if ($claimed === 1) {
            $generator->generate(CouponBatch::findOrFail($this->batchId));
        }
    }
}
