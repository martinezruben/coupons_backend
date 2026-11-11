<?php

namespace App\Observers;

use App\Jobs\SendContingencyNotificationJob;
use App\Jobs\SendContingencyResolvedJob;
use App\Models\Location;
use Illuminate\Support\Facades\Log;

class LocationContingencyObserver
{
    public function updated(Location $location): void
    {
        // Solo reacciona al cambio de is_active hecho en ESTE guardado
        if (! $location->wasChanged('is_active')) {
            return;
        }

        if ($location->is_active) {
            // Location activated - entering contingency
            $this->activateContingency($location);
        } else {
            // Location deactivated - resolving contingency
            $this->resolveContingency($location);
        }
    }

    private function activateContingency(Location $location): void
    {
        // updateQuietly: sin eventos. update() dentro de updated() volvía a entrar
        // en este observer sin fin y tumbaba el proceso.
        $location->updateQuietly([
            'contingency_started_at' => now(),
            'contingency_reminder_sent_at' => now(),
        ]);

        Log::info("Contingency activated for location: {$location->name}");

        SendContingencyNotificationJob::dispatch($location);
    }

    private function resolveContingency(Location $location): void
    {
        $location->updateQuietly([
            'contingency_started_at' => null,
            'contingency_reminder_sent_at' => null,
        ]);

        Log::info("Contingency resolved for location: {$location->name}");

        SendContingencyResolvedJob::dispatch($location);
    }
}
