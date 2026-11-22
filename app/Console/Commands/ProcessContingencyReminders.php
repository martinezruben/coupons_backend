<?php

namespace App\Console\Commands;

use App\Jobs\ScheduleContingencyReminderJob;
use App\Models\Location;
use App\Models\SystemParameter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessContingencyReminders extends Command
{
    protected $signature = 'contingency:process-reminders';

    protected $description = 'Process contingency reminders for locations in contingency';

    public function handle()
    {
        $systemParams = SystemParameter::current();

        if (! $systemParams->contingency_enabled) {
            $this->info('Contingency notifications are disabled');

            return 0;
        }

        $resendHours = $systemParams->contingency_resend_hours;

        // Find locations in contingency that need a reminder
        $locationsNeedingReminder = Location::where('contingency_started_at', '!=', null)
            ->where(function ($query) use ($resendHours) {
                // Fecha límite calculada aquí: el SQL propio de SQLite no existe en MySQL (producción)
                $query->whereNull('contingency_reminder_sent_at')
                    ->orWhere('contingency_reminder_sent_at', '<', now()->subHours((int) $resendHours));
            })
            ->get();

        if ($locationsNeedingReminder->isEmpty()) {
            $this->info('No locations need reminder emails');

            return 0;
        }

        $count = 0;
        foreach ($locationsNeedingReminder as $location) {
            try {
                ScheduleContingencyReminderJob::dispatch($location);
                $count++;
                $this->info("Scheduled reminder for location: {$location->name}");
                Log::info("Contingency reminder scheduled for location: {$location->name}");
            } catch (\Exception $e) {
                $this->error("Failed to schedule reminder for location {$location->name}: {$e->getMessage()}");
                Log::error("Failed to schedule reminder for location {$location->name}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$count} contingency reminder(s)");

        return 0;
    }
}
