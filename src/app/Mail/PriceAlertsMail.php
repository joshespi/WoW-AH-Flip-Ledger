<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * One digest of everything that fired for a user in a run. Carries a signed one-click
 * unsubscribe (RFC 8058) so mail clients can offer their own button as well as the link.
 */
class PriceAlertsMail extends Mailable
{
    /** @param  list<array{kind: string, item_id: int, name: string, on: bool, floor: ?int, target: ?int}>  $alerts */
    public function __construct(public User $user, public array $alerts) {}

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('alerts.unsubscribe', ['user' => $this->user->id]);
    }

    public function envelope(): Envelope
    {
        $first = $this->alerts[0];

        return new Envelope(subject: count($this->alerts) === 1
            ? __('Price alert: :name', ['name' => $first['name']])
            : __(':n price alerts', ['n' => count($this->alerts)]));
    }

    public function content(): Content
    {
        $groups = [];

        foreach ($this->alerts as $a) {
            $groups[$a['kind']][] = $a;
        }

        return new Content(
            view: 'mail.price-alerts',
            text: 'mail.price-alerts-text',
            with: ['groups' => $groups, 'unsubscribe' => $this->unsubscribeUrl(), 'settings' => route('settings.edit')],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
