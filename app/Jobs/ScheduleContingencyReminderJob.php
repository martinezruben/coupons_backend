<?php

namespace App\Jobs;

use App\Mail\ContingencyReminderMail;
use App\Models\ContingencyAuditLog;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Services\MailConfigurationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ScheduleContingencyReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Location $location) {}

    public function handle(): void
    {
        // Verify location is still in contingency
        $this->location->refresh();

        if (! $this->location->isInContingency()) {
            Log::info("Location {$this->location->name} is no longer in contingency, skipping reminder");

            return;
        }

        $systemParams = SystemParameter::current();

        if (! $systemParams->contingency_enabled) {
            Log::info("Contingency notifications disabled, skipping reminder for location: {$this->location->name}");

            return;
        }

        $emailList = $systemParams->contingency_email_list ?? [];

        if (empty($emailList)) {
            Log::warning("No contingency emails configured, skipping reminder for location: {$this->location->name}");

            return;
        }

        // Send reminder emails
        // SMTP configurado en el panel (no el mailer del .env)
        app(MailConfigurationService::class)->applyToMailer();

        foreach ($emailList as $email) {
            try {
                Mail::to($email)->send(new ContingencyReminderMail($this->location));
                Log::info("Contingency reminder email sent to {$email} for location: {$this->location->name}");
            } catch (\Exception $e) {
                Log::error("Failed to send contingency reminder to {$email}: {$e->getMessage()}");
            }
        }

        // Update reminder timestamp
        $this->location->update(['contingency_reminder_sent_at' => now()]);

        // Log the reminder
        ContingencyAuditLog::create([
            'location_id' => $this->location->id,
            'location_name' => $this->location->name,
            'event' => 'reminder_sent',
            'sent_to' => json_encode($emailList),
            'message' => 'Contingency reminder sent to '.count($emailList).' email(s)',
        ]);

        // Schedule next reminder if still in contingency
        if ($this->location->isInContingency()) {
            self::dispatch($this->location)->delay(
                now()->addHours($systemParams->contingency_resend_hours)
            );
        }
    }
}
