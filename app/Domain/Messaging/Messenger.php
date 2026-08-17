<?php

namespace App\Domain\Messaging;

use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a customer a message, once.
 *
 * Two rules, both learned from v1.
 *
 * Nothing is delivered unless delivery is explicitly switched on AND we are in
 * production. v1's test suite sent real WhatsApp messages to real customers'
 * phones, because the only guard was a config flag that was set everywhere.
 * The check here is deliberately belt and braces: an environment check that
 * cannot be turned off by a stray .env line, and a config flag on top.
 *
 * And every attempt is written down before it is sent, with a unique key that
 * makes a second message about the same thing on the same day impossible. v1
 * had no record at all, so a job that ran twice messaged twice.
 */
class Messenger
{
    /**
     * A number the provider will actually route to.
     *
     * Numbers are stored as ten digits, because that is what a customer reads
     * off their own phone and what every screen shows. The provider needs the
     * country code, and a bare ten digit number is not rejected loudly - it is
     * accepted and dropped, which looks exactly like a message that was sent.
     *
     * v1 did the same thing at the same point: '91'.$mobileNo.
     */
    public static function dialable(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        // Already carries a country code - from an import, or typed in full.
        if (strlen($digits) > 10) {
            return $digits;
        }

        return self::COUNTRY_CODE.$digits;
    }

    /**
     * India. Kept as a constant rather than scattered through the code, so the
     * day this business crosses a border there is one line to find.
     */
    private const COUNTRY_CODE = '91';

    /**
     * Is a message actually going to leave the building?
     */
    public function deliveryEnabled(): bool
    {
        // Never during tests, whatever the configuration says. This is the
        // line that stops a test suite messaging a real customer.
        if (app()->runningUnitTests()) {
            return false;
        }

        return app()->isProduction() && (bool) config('services.whatsapp.enabled');
    }

    /**
     * Why a message would not be delivered right now, for the record.
     */
    public function suppressionReason(): ?string
    {
        if (app()->runningUnitTests()) {
            return 'Running tests.';
        }

        if (! app()->isProduction()) {
            return 'Not production ('.app()->environment().').';
        }

        if (! config('services.whatsapp.enabled')) {
            return 'Delivery is switched off.';
        }

        return null;
    }

    /**
     * Send one message about one subscription.
     *
     * Returns null when there is already a message of this purpose today - the
     * caller has nothing to do, and that is not a failure.
     *
     * @param  array<string, string>  $variables  Template placeholders
     */
    /**
     * Send using a stored template.
     *
     * The wording comes from the database, so a template edited in the office
     * changes what the nightly job sends as well as what a person sends by
     * hand. A missing or switched-off template sends nothing rather than
     * falling back to wording nobody has approved.
     */
    /**
     * Send the message for a purpose, using whatever wording is stored for it.
     *
     * This is what the triggers around the application call, so each of them is
     * one line and none of them knows about templates, placeholders or the
     * suppression rules. A purpose with no template, or one switched off, sends
     * nothing and says so in the log - deliberately, because falling back to
     * wording nobody has approved is worse than staying quiet.
     *
     * @param  array<string, string>  $extra  Values only the caller knows
     */
    public function notify(
        Subscription $subscription,
        MessagePurpose $purpose,
        array $extra = [],
        ?string $toPhone = null,
    ): ?Message {
        $template = MessageTemplate::query()->where('key', $purpose->value)->first();

        if (! $template) {
            Log::warning('No message template for this purpose; nothing sent.', [
                'purpose' => $purpose->value,
                'subscription_id' => $subscription->id,
            ]);

            return null;
        }

        if (! $template->status) {
            Log::info('Not sending: that template is switched off.', [
                'template' => $template->key,
                'subscription_id' => $subscription->id,
            ]);

            return null;
        }

        // The relations the wording may refer to are loaded by valuesFor, so
        // every caller here is a single line.
        return $this->send(
            $subscription,
            $purpose,
            $template->render(MessageTemplate::valuesFor($subscription, $extra)),
            template: $template,
            toPhone: $toPhone,
        );
    }

    public function sendTemplate(
        Subscription $subscription,
        MessageTemplate $template,
        ?Carbon $on = null,
        ?string $purposeOverride = null,
    ): ?Message {
        if (! $template->status) {
            Log::info('Not sending: that template is switched off.', [
                'template' => $template->key,
                'subscription_id' => $subscription->id,
            ]);

            return null;
        }

        $body = $template->render(MessageTemplate::valuesFor($subscription));

        /*
         * The purpose is what stops the same customer being told the same
         * thing twice in a day. A template maps to one, and anything without a
         * matching purpose is keyed on the template itself so two different
         * templates can both go out.
         */
        $purpose = MessagePurpose::tryFrom($purposeOverride ?? $template->key);

        return $this->send(
            $subscription,
            $purpose ?? MessagePurpose::RenewalDue,
            $body,
            on: $on,
            template: $template,
            dedupeKey: $purpose ? null : $template->key,
        );
    }

    public function send(
        Subscription $subscription,
        MessagePurpose $purpose,
        string $body,
        array $variables = [],
        ?Carbon $on = null,
        ?MessageTemplate $template = null,
        ?string $dedupeKey = null,
        ?string $toPhone = null,
    ): ?Message {
        $on ??= Carbon::today();

        /*
         * Almost always the customer. The exception is the message that tells
         * the office a new plan needs a cleaner, which goes to a number in the
         * settings - v1 had that number written into the source.
         */
        $recipient = $toPhone ?: $subscription->customer?->phone;

        if (! $recipient) {
            Log::info('Nothing to message: the customer has no phone number.', [
                'subscription_id' => $subscription->id,
            ]);

            return null;
        }

        $reason = $this->suppressionReason();

        try {
            /*
             * Written first, and with its final status where it is suppressed.
             * Recording after sending would lose the record if the process died
             * mid-send - and then the customer gets a second message tomorrow.
             */
            $message = Message::create([
                'branch_id' => $subscription->branch_id,
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'channel' => 'whatsapp',
                'purpose' => $purpose,
                // The provider template that was actually used, so a change of
                // wording is visible in the history rather than only in code.
                'template' => $template?->provider_template ?? $purpose->template(),
                'recipient' => $recipient,
                'body' => $body,
                'status' => $reason ? MessageStatus::Suppressed : MessageStatus::Queued,
                'suppressed_reason' => $reason,
                'sent_on' => $on->toDateString(),
            ]);
        } catch (QueryException $e) {
            // The unique key fired: already told them today. Not an error.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }

            throw $e;
        }

        if ($reason) {
            // Logged in full so development can see exactly what a customer
            // would have received.
            Log::info('Message suppressed: '.$reason, [
                // As it would have been addressed, country code and all, so the
                // log shows what would really have gone out.
                'to' => self::dialable($recipient),
                'purpose' => $purpose->value,
                'body' => $body,
            ]);

            return $message;
        }

        $this->deliver($message, $variables);

        return $message;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function deliver(Message $message, array $variables): void
    {
        try {
            $response = Http::withHeaders([
                'authkey' => (string) config('services.whatsapp.key'),
            ])
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->post(config('services.whatsapp.url'), [
                    'integrated_number' => config('services.whatsapp.number'),
                    'content_type' => 'template',
                    'payload' => [
                        // With the country code, as v1 sent it. A bare ten
                        // digit number is not routable and the provider
                        // silently drops it.
                        'to' => self::dialable($message->recipient),
                        'type' => 'template',
                        'template' => [
                            'name' => $message->template,
                            'language' => ['code' => 'en', 'policy' => 'deterministic'],
                            'components' => $variables,
                        ],
                    ],
                ]);

            if ($response->failed()) {
                $message->forceFill([
                    'status' => MessageStatus::Failed,
                    'error' => 'HTTP '.$response->status().': '.$response->body(),
                ])->save();

                Log::warning('A message could not be delivered.', [
                    'message_id' => $message->id,
                    'status' => $response->status(),
                ]);

                return;
            }

            $message->forceFill([
                'status' => MessageStatus::Sent,
                'provider_id' => $response->json('request_id'),
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            /*
             * A messaging failure must never take down the job that triggered
             * it. The row is marked failed and the run carries on: a customer
             * not being told is bad, but a whole night's reminders stopping
             * because one number was malformed is worse.
             */
            $message->forceFill([
                'status' => MessageStatus::Failed,
                'error' => $e->getMessage(),
            ])->save();

            Log::error('A message threw while being delivered.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
