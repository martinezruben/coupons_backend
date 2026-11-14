<?php

namespace App\Console\Commands;

use App\Services\CouponActivationService;
use Illuminate\Console\Command;

class ActivateDueCoupons extends Command
{
    protected $signature = 'coupons:activate-due';

    protected $description = 'Activa los cupones de las órdenes programadas cuya fecha ya llegó';

    public function handle(CouponActivationService $service): int
    {
        $applied = $service->applyDue();
        $this->info("Órdenes de activación aplicadas: {$applied}");

        return self::SUCCESS;
    }
}
