<?php

namespace App\Mail;

use App\Models\Location;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContingencyResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Location $location) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "✅ Contingencia resuelta - Sitio {$this->location->name}"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contingency-resolved',
            with: [
                'location' => $this->location,
            ]
        );
    }
}
