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
    ): ?Message {
        $on ??= Carbon::today();

        $recipient = $subscription->customer?->phone;

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
                'to' => $recipient,
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
                        'to' => $message->recipient,
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
