<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Transport\OutboundMessage;
use App\Domain\Messaging\Transport\TransportChain;
use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * How a message actually reaches a phone.
     *
     * Injected rather than reached for, so a test can hand this a transport
     * that records instead of sends - and so nothing in here needs to know
     * whether that is MSG91, Twilio, WhatsApp or SMS.
     */
    public function __construct(private readonly TransportChain $transports) {}

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
     * Who a message for this number actually goes to.
     *
     * The number itself, unless MSG91_TEST_RECIPIENT is set - then everything
     * goes there, and the log says so each time.
     *
     * Shared by every way a message leaves, because the redirect is only safe
     * if it is total. The sign-in code used to go its own way and skip it, so
     * while testing on the live site every other message reached the tester
     * and the one-time code went to whatever number had been typed in.
     *
     * @param  array<string, mixed>  $context  added to the log line
     */
    public static function recipientFor(string $phone, array $context = []): string
    {
        $testRecipient = (string) config('services.whatsapp.test_recipient');

        if ($testRecipient === '') {
            return $phone;
        }

        Log::warning('Message redirected to the test number.', $context + [
            'intended_for' => $phone,
            'sent_to' => $testRecipient,
            'why' => 'MSG91_TEST_RECIPIENT is set. Unset it and no customer is messaged from here.',
        ]);

        return $testRecipient;
    }

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

        if (! self::scheduledMessagesAllowed()) {
            return 'Scheduled messages are switched off on this server (SCHEDULED_MESSAGES).';
        }

        return null;
    }

    /**
     * May a job, rather than a person, send this?
     *
     * Anything running from the console - the scheduler, or a command typed
     * by hand - is a job. On a test or staging copy those run against real
     * data with nobody watching: the morning reminders went out seventy-five
     * at a time to the one test number, WhatsApp read it as spam, and then
     * refused to deliver the messages somebody was actually trying to test.
     *
     * Off unless a server says otherwise, because the dangerous mistake is the
     * quiet one: a copy that messages people because nobody thought to stop
     * it. The live site sets SCHEDULED_MESSAGES=true; check-integrations says
     * which way it is.
     *
     * What a person does from a screen - a reminder sent from the plans list,
     * a receipt after a payment - is a web request and is never held by this.
     * The jobs themselves still run: a plan past its grace period is still
     * paused, and the message is recorded with this as the reason it stayed.
     */
    public static function scheduledMessagesAllowed(): bool
    {
        return ! app()->runningInConsole() || (bool) config('messaging.scheduled');
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
        /**
         * The day this counts as having been sent on.
         *
         * Only the nightly jobs pass it, and only so `--date` can rehearse a
         * run against a date that is not today. It is also half the key that
         * stops a customer hearing the same thing twice in one day, so a job
         * pretending it is Tuesday must dedupe against Tuesday.
         */
        ?Carbon $on = null,
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
        $values = MessageTemplate::valuesFor($subscription, $extra);

        return $this->send(
            $subscription,
            $purpose,
            $template->render($values),
            // The same values the body was rendered from, ordered for the
            // provider by the template itself. Passing the values rather than a
            // ready-made list is what stops the two from ever describing
            // different variables - see ProviderTemplate.
            variables: $this->variablesFor($template, $subscription, $values),
            on: $on,
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

        $values = MessageTemplate::valuesFor($subscription);
        $body = $template->render($values);

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
            variables: $this->variablesFor($template, $subscription, $values),
            on: $on,
            template: $template,
            dedupeKey: $purpose ? null : $template->key,
        );
    }

    /**
     * What the provider is told to fill the template in with.
     *
     * Built from the same values the readable body was rendered from, and
     * ordered by the template itself, so the two cannot disagree about how many
     * variables there are or what order they come in. Getting that wrong is
     * invisible from our side: the `messages` row still reads perfectly, and the
     * only sign of trouble is a rejection from the provider or - worse - a
     * delivered message with the car number where the name should be.
     *
     * @param  array<string, string|int|float|null>  $values
     * @return array<int, array<string, mixed>>
     */
    private function variablesFor(
        MessageTemplate $template,
        Subscription $subscription,
        array $values,
    ): array {
        $provider = ProviderTemplate::for($template);

        /*
         * A renewal message carries a button that opens this plan ready to pay
         * for, and the token is made the first time one is sent. Asked for only
         * where the template actually has a button, so no plan is given a
         * permanent link because somebody sent it a receipt.
         */
        $buttonValue = $provider->button ? $subscription->renewToken() : null;

        return $provider->components($values, $buttonValue);
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

        /*
         * Everything to one number instead, while somebody is testing.
         *
         * There is a real need behind this: the delivery gate is production-only
         * by design, so the only way to see a message actually arrive is to send
         * a real one - and on the live site that means a real customer's phone.
         *
         * It was briefly done by assigning a number over this line in the
         * source. That is the version of this that ends up deployed: it cannot
         * be switched off without a release, it is invisible to anyone reading
         * the configuration, and every customer silently stops being messaged
         * while one phone gets everybody's mail. The test suite caught it, which
         * is the only reason it did not ship.
         *
         * As a setting it is off unless somebody sets it, it is visible in .env,
         * check-integrations reports it, and every redirected message says so in
         * the log.
         */
        if ($recipient) {
            $recipient = self::recipientFor($recipient, ['subscription_id' => $subscription->id]);
        }

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
                /*
                 * Said out loud, because this is the one path that leaves no
                 * trace anywhere.
                 *
                 * It is correct behaviour - the customer has already heard this
                 * today and must not hear it twice - but it is indistinguishable
                 * from a broken send to whoever is testing: no message arrives,
                 * no row appears on the Messages screen, and nothing is logged.
                 * Somebody testing the same plan twice in one afternoon reads
                 * that as the whole feature being dead.
                 */
                Log::info('Not sending: this customer already heard this today.', [
                    'subscription_id' => $subscription->id,
                    'purpose' => $purpose->value,
                    'sent_on' => $on->toDateString(),
                    'why' => 'One message per plan per purpose per day. Delete that row, '
                        .'use a different plan, or pass a later --date to send again.',
                ]);

                return null;
            }

            throw $e;
        }

        /*
         * The address behind the button, worked out once.
         *
         * Read by two things that must agree: the suppressed log, which is the
         * only way to see a renewal link outside production, and the SMS
         * transport, which has no buttons and puts it in the text instead.
         */
        $link = $this->buttonUrlFor($subscription, $template);

        if ($reason) {
            // Logged in full so development can see exactly what a customer
            // would have received.
            Log::info('Message suppressed: '.$reason, array_filter([
                // As it would have been addressed, country code and all, so the
                // log shows what would really have gone out.
                'to' => self::dialable($recipient),
                'purpose' => $purpose->value,
                'body' => $body,
                'template' => $message->template,
                /*
                 * The variables as the provider would have received them, and
                 * the address behind any button.
                 *
                 * Delivery is off everywhere except production, so without this
                 * there is no way to see that the ordered list matches the
                 * approved template, or to open a renewal link and check the
                 * page it lands on, short of messaging a real customer.
                 */
                'components' => $variables ?: null,
                'button_url' => $link,
            ], fn ($value) => $value !== null));

            return $message;
        }

        $this->deliver($message, $variables, $link);

        return $message;
    }

    /**
     * The address a message's button would open, for the suppressed log.
     *
     * Null for the great majority of messages, which carry no button. Reading
     * it costs a token being made, so it is asked only where there is one.
     */
    private function buttonUrlFor(Subscription $subscription, ?MessageTemplate $template): ?string
    {
        if (! $template || ! TemplateButton::forKey($template->key)) {
            return null;
        }

        return $subscription->renewUrl();
    }

    /**
     * Hand the message to whichever transport will take it.
     *
     * This used to post to MSG91 from here. It no longer knows who carries a
     * message or how: the chain in config/messaging.php decides, and the same
     * code sends by WhatsApp, by SMS, or by WhatsApp-and-then-SMS depending on
     * one line of configuration.
     *
     * What stays here is everything about the record. Which transport won, and
     * therefore what the customer actually received, is written onto the row -
     * an SMS has no button under it, and "why did this one look different" has
     * to be answerable from the Messages screen rather than from a log nobody
     * kept.
     *
     * @param  array<int, array<string, mixed>>  $variables  Provider components
     */
    private function deliver(Message $message, array $variables, ?string $link): void
    {
        try {
            $result = $this->transports->send(new OutboundMessage(
                to: $message->recipient,
                body: (string) $message->body,
                template: $message->template,
                components: $variables,
                link: $link,
            ));
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

            return;
        }

        if (! $result->ok) {
            $message->forceFill([
                'status' => MessageStatus::Failed,
                'error' => $result->error,
            ])->save();

            /*
             * The provider's own words, not just a status.
             *
             * This used to log the number alone, so a 401 said only that
             * something was refused - and the reason sat in `messages.error`,
             * which means knowing to go and look. A 401 is nearly always the
             * credentials: MSG91_AUTH_KEY holding the integrated number rather
             * than the account key is the one that has actually happened.
             */
            Log::warning('A message could not be delivered.', [
                'message_id' => $message->id,
                'transport' => $result->transport,
                'template' => $message->template,
                'error' => Str::limit((string) $result->error, 500),
                'hint' => str_contains((string) $result->error, '401')
                    ? 'The provider refused the credentials. Run eswachh:check-integrations.'
                    : null,
            ]);

            return;
        }

        $message->forceFill([
            'status' => MessageStatus::Sent,
            // What it actually went out as, which is not always what was asked
            // for: a fallback turns a WhatsApp message into an SMS.
            'channel' => $result->channel,
            'provider_id' => $result->providerId,
            'sent_at' => now(),
        ])->save();
    }
}
