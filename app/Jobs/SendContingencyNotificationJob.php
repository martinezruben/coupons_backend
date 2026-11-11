<?php

namespace App\Jobs;

use App\Mail\ContingencyActivatedMail;
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

class SendContingencyNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Location $location) {}

    public function handle(): void
    {
        $systemParams = SystemParameter::current();

        if (! $systemParams->contingency_enabled) {
            Log::info("Contingency notifications disabled, skipping for location: {$this->location->name}");

            return;
        }

        $emailList = $systemParams->contingency_email_list ?? [];

        if (empty($emailList)) {
            Log::warning("No contingency emails configured, skipping for location: {$this->location->name}");

            return;
        }

        // SMTP configurado en el panel (no el mailer del .env)

        app(MailConfigurationService::class)->applyToMailer();

        foreach ($emailList as $email) {
            try {
                Mail::to($email)->send(new ContingencyActivatedMail($this->location));
                Log::info("Contingency activated email sent to {$email} for location: {$this->location->name}");
            } catch (\Exception $e) {
                Log::error("Failed to send contingency email to {$email}: {$e->getMessage()}");
            }
        }

        ContingencyAuditLog::create([
            'location_id' => $this->location->id,
            'location_name' => $this->location->name,
            'event' => 'activated',
            'sent_to' => json_encode($emailList),
            'message' => 'Contingency activated notification sent to '.count($emailList).' email(s)',
        ]);

        ScheduleContingencyReminderJob::dispatch($this->location)->delay(
            now()->addHours($systemParams->contingency_resend_hours)
        );
    }
}
