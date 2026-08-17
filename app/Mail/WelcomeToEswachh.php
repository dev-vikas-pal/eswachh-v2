<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Support\Settings\SiteSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, when somebody's first plan is paid for.
 *
 * v1 mailed a generated password. There is no password here - a customer signs
 * in with a code sent to the number they already proved - so what this carries
 * instead is the plan they bought and how to get in.
 *
 * Only ever sent when a customer gave an email address. It is optional on the
 * form and most do not, which is why the same information is in the WhatsApp
 * welcome as well rather than only here.
 */
class WelcomeToEswachh extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        $business = (string) SiteSettings::get('business_name', 'Eswachh');

        return new Envelope(subject: "Welcome to {$business}");
    }

    public function content(): Content
    {
        $this->subscription->loadMissing(['customer', 'vehicle', 'package', 'duration']);

        return new Content(
            markdown: 'mail.welcome',
            with: [
                'name' => $this->subscription->customer?->name ?? 'there',
                'car' => $this->subscription->vehicle?->registration,
                'package' => $this->subscription->package?->name,
                'months' => $this->subscription->duration?->months,
                'renews' => $this->subscription->period_end?->format('j M Y'),
                'amount' => number_format($this->subscription->amount(), 2),
                'phone' => $this->subscription->customer?->phone,
                'signInUrl' => rtrim((string) config('app.url'), '/').'/login',
                'office' => (string) SiteSettings::get('contact_phone', ''),
            ],
        );
    }
}
