<?php

namespace App\Mail;

use App\Models\Location;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContingencyActivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Location $location) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🚨 Entrada en contingencia - Sitio {$this->location->name}"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contingency-activated',
            with: [
                'location' => $this->location,
                'activatedAt' => $this->location->contingency_started_at,
            ]
        );
    }
}
