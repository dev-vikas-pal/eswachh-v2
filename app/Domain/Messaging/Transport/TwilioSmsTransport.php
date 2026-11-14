<?php

namespace App\Domain\Messaging\Transport;

use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\ProviderTemplate;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Plain SMS through Twilio, for when WhatsApp could not carry it.
 *
 * The simplest transport and the one with the most rules around it.
 *
 * Simple, because the wording is already rendered: every message this system
 * sends is written out in full and stored on the `messages` row, so SMS needs
 * no template, no approval and no variable ordering. It sends the text.
 *
 * Two things it must handle that WhatsApp does not.
 *
 * There are no buttons. A message that says "Tap Renew now below" arrives under
 * nothing at all, so the address behind that button is appended to the text
 * instead - see withLink().
 *
 * And India requires DLT. Every sender and every template must be registered
 * with the telecom regulator through an operator before a single message
 * reaches an Indian number, and Twilio passes the registered identifiers
 * through on each send. Nothing here can shortcut that: without registration
 * Twilio accepts the request and the carrier drops it, which looks exactly like
 * a message that was sent.
 */
class TwilioSmsTransport implements MessageTransport
{
    /**
     * A single SMS is 160 characters, or 70 if anything in it is not plain
     * Latin. Longer messages are split and billed per part, so the length of
     * the wording is a cost decision rather than a style one.
     */
    private const SINGLE_SEGMENT = 160;

    public function name(): string
    {
        return 'twilio.sms';
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return (string) config('services.twilio.sid') !== ''
            && (string) config('services.twilio.token') !== ''
            && (string) config('services.twilio.sms_from') !== '';
    }

    public function send(OutboundMessage $message): TransportResult
    {
        if (! $this->isConfigured()) {
            return TransportResult::unavailable(
                $this->name(),
                $this->channel(),
                'Twilio has no account SID, token or SMS sender.',
            );
        }

        if (! $message->hasReadableBody()) {
            /*
             * Nothing to say. Only possible for a message whose whole meaning
             * lived in an approved template, and sending an empty SMS is worse
             * than sending none - the customer gets a blank from a number they
             * do not recognise.
             */
            return TransportResult::failed(
                $this->name(),
                $this->channel(),
                'That message has no readable wording, so there is nothing to send as text.',
                worthRetryingElsewhere: false,
            );
        }

        $body = $this->withLink($message);
        $content = $this->content($message);

        $payload = [
            'To' => '+'.Messenger::dialable($message->to),
            'From' => (string) config('services.twilio.sms_from'),
        ];

        /*
         * A template created from the office screen is addressed by its Content
         * SID. Wording that has never been sent to Twilio still goes as text,
         * which is what every SMS did before that button existed.
         */
        if ($content) {
            $payload['ContentSid'] = $content['sid'];

            if ($content['variables'] !== []) {
                $payload['ContentVariables'] = json_encode($content['variables']);
            }
        } else {
            $payload['Body'] = $body;
        }

        return $this->post($payload, $body);
    }

    /**
     * Put the button's address into the text, because SMS has no buttons.
     *
     * Appended rather than substituted into the wording. The sentence about
     * tapping reads a little oddly with a link under it, which is a smaller
     * problem than the alternative: a renewal message that mentions a button
     * nobody can see and gives no other way to pay.
     *
     * Stage 3 replaces this with wording written for SMS. Until then this is
     * the honest version - the link is there, and it works.
     */
    private function withLink(OutboundMessage $message): string
    {
        if (! $message->link) {
            return $message->body;
        }

        return rtrim($message->body)."\n".$message->link;
    }

    /**
     * The Content SID for this wording, and the values in the order it was
     * created. Null when the office has not created an SMS template for it.
     *
     * @return array{sid: string, variables: array<string, string>}|null
     */
    private function content(OutboundMessage $message): ?array
    {
        if (! $message->template) {
            return null;
        }

        try {
            $row = MessageTemplate::query()
                ->where('provider_template', $message->template)
                ->whereNotNull('sms_content_sid')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (! $row) {
            return null;
        }

        $provider = ProviderTemplate::for($row);
        $variables = [];
        $position = 1;

        foreach ($message->components as $component) {
            if (($component['type'] ?? '') !== 'body') {
                continue;
            }

            foreach ($component['parameters'] ?? [] as $parameter) {
                $variables[(string) $position++] = (string) ($parameter['text'] ?? '');
            }
        }

        if ($provider->button?->isDynamic() && $message->link) {
            $variables[(string) $position] = $message->link;
        }

        return [
            'sid' => (string) $row->sms_content_sid,
            'variables' => $variables,
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function post(array $payload, string $body): TransportResult
    {
        $sid = (string) config('services.twilio.sid');

        /*
         * India's regulator again: the registered entity and template ids
         * travel with every message, and Twilio rejects the send without them
         * once the account is set up for India.
         */
        foreach (['entity_id' => 'DltEntityId', 'template_id' => 'DltTemplateId'] as $key => $field) {
            if ($value = (string) config('services.twilio.dlt.'.$key)) {
                $payload[$field] = $value;
            }
        }

        try {
            $response = Http::withBasicAuth($sid, (string) config('services.twilio.token'))
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload);
        } catch (\Throwable $e) {
            return TransportResult::failed($this->name(), $this->channel(), $e->getMessage());
        }

        if ($response->failed()) {
            return TransportResult::failed(
                $this->name(),
                $this->channel(),
                'HTTP '.$response->status().': '.$response->body(),
            );
        }

        return TransportResult::sent($this->name(), $this->channel(), $response->json('sid'));
    }

    /**
     * How many SMS this wording will be billed as.
     *
     * Not used to decide anything - it is for the check command, so the cost of
     * a wording change is visible when it is made rather than on the invoice.
     */
    public static function segments(string $body): int
    {
        $limit = Str::isAscii($body) ? self::SINGLE_SEGMENT : 70;

        return max(1, (int) ceil(mb_strlen($body) / $limit));
    }
}
