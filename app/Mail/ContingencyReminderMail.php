<?php

namespace App\Mail;

use App\Models\Location;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContingencyReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Location $location) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "⏰ Recordatorio: Sitio {$this->location->name} en contingencia"
        );
    }

    public function content(): Content
    {
        $hoursInContingency = $this->location->contingency_started_at
            ? now()->diffInHours($this->location->contingency_started_at)
            : 0;

        return new Content(
            view: 'emails.contingency-reminder',
            with: [
                'location' => $this->location,
                'hoursInContingency' => $hoursInContingency,
            ]
        );
    }
}
