<?php

namespace App\Domain\Messaging\Transport;

use App\Domain\Messaging\Messenger;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through Twilio.
 *
 * The same WhatsApp, and worth being clear about what changing to it does and
 * does not save. Meta charges per conversation and that charge is identical
 * whichever business solution provider carries it - a marketing conversation
 * costs the same through Twilio as through MSG91. What is saved is the
 * provider's own platform fee, which for a low volume is most of the bill.
 *
 * Twilio addresses a template by its Content SID (HX...) rather than by name,
 * and takes the variables as a JSON object keyed by position. Meta's component
 * array is not accepted here, so the ordered values are flattened into
 * {"1": "...", "2": "..."} - which is the same ordering ProviderTemplate worked
 * out, expressed the way this provider asks for it.
 *
 * A live send needs the WhatsApp-enabled sender in TWILIO_WHATSAPP_FROM and a
 * Content SID for that template in .env. The sandbox number and a plain Body
 * are both refused by this account.
 */
class TwilioWhatsAppTransport implements MessageTransport
{
    public function name(): string
    {
        return 'twilio.whatsapp';
    }

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return (string) config('services.twilio.sid') !== ''
            && (string) config('services.twilio.token') !== ''
            && (string) config('services.twilio.whatsapp_from') !== '';
    }

    public function send(OutboundMessage $message): TransportResult
    {
        if (! $this->isConfigured()) {
            return TransportResult::unavailable(
                $this->name(),
                $this->channel(),
                'Twilio has no account SID, token or WhatsApp sender.',
            );
        }

        /*
         * The Content SID for this template, looked up from the name.
         *
         * Twilio does not accept the name Meta approved; it issues its own
         * identifier when the template is created through its Content API. The
         * mapping lives in configuration rather than in the database because it
         * is a fact about one Twilio account, not about the wording - the same
         * reason the MSG91 name lives on the template row and not in code.
         */
        $contentSid = $this->contentSidFor($message->template);

        if (! $contentSid) {
            return TransportResult::failed(
                $this->name(),
                $this->channel(),
                "No Twilio content SID is mapped for template [{$message->template}]. "
                    .'Set '.$this->envName($message->template).' in .env.',
            );
        }

        $payload = [
            'To' => 'whatsapp:+'.Messenger::dialable($message->to),
            'From' => 'whatsapp:+'.ltrim((string) config('services.twilio.whatsapp_from'), '+'),
            'ContentSid' => $contentSid,
        ];

        if ($variables = $this->variables($message)) {
            $payload['ContentVariables'] = json_encode($variables);
        }

        return $this->post($payload);
    }

    /**
     * Meta's component array, flattened to the object Twilio wants.
     *
     * Body values become "1", "2", ...; a URL button's value continues that
     * numbering, because Twilio treats the whole template as one variable space
     * rather than splitting it by component the way Meta does.
     *
     * @return array<string, string>
     */
    private function variables(OutboundMessage $message): array
    {
        $variables = [];
        $position = 1;

        foreach ($message->components as $component) {
            foreach ($component['parameters'] ?? [] as $parameter) {
                $variables[(string) $position++] = (string) ($parameter['text'] ?? '');
            }
        }

        return $variables;
    }

    private function contentSidFor(?string $template): ?string
    {
        if (! $template) {
            return null;
        }

        /*
         * The row stores the name approved on MSG91. On this account that name
         * carries an extra _subs, while the env keys use the template's own
         * name. Both have to find the same Content SID.
         */
        foreach ([$template, (string) preg_replace('/_subs$/', '', $template)] as $name) {
            if ($sid = config('messaging.twilio.content.'.$name)) {
                return $sid;
            }
        }

        return null;
    }

    /**
     * The .env key a missing template should be filled in under.
     */
    private function envName(?string $template): string
    {
        $name = (string) preg_replace('/_subs$/', '', (string) $template);
        $name = str_starts_with($name, 'v2_') ? substr($name, 3) : $name;

        return 'TWILIO_CONTENT_'.strtoupper($name);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function post(array $payload): TransportResult
    {
        $sid = (string) config('services.twilio.sid');

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
}
