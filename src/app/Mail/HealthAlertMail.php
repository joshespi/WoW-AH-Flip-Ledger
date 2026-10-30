<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Operations notice for admins: what is newly broken, still broken, or fixed. */
class HealthAlertMail extends Mailable
{
    /**
     * @param  array<string, string>  $problems  label => what is wrong (new problems and reminders)
     * @param  list<string>  $recovered  labels that are healthy again
     */
    public function __construct(public array $problems, public array $recovered) {}

    public function envelope(): Envelope
    {
        $name = config('app.name');

        return new Envelope(subject: $this->problems !== []
            ? "[{$name}] Problem: ".implode(', ', array_keys($this->problems))
            : "[{$name}] Recovered: ".implode(', ', $this->recovered));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.health-alert', with: ['admin' => route('admin.overview')]);
    }
}
