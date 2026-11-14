<?php

namespace App\Domain\Messaging\Transport;

use App\Domain\Messaging\Messenger;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through MSG91, which is how everything has been sent until now.
 *
 * The bulk endpoint does not accept Meta's component array. It wants
 * `to_and_components`: named `body_1`, `body_2`, `button_1` entries whose
 * values match the Code JSON on the approved template. Messenger still builds
 * the Meta shape, because that is what the other transports read; this class
 * is the only place that rewrites it.
 *
 * Kept after the move to Twilio rather than deleted. During a cutover the
 * ability to put one line back in .env and have last week's behaviour return is
 * worth more than the file it costs - and the three templates approved against
 * this account are real work that should not be stranded by a rollback.
 */
class Msg91WhatsAppTransport implements MessageTransport
{
    public function name(): string
    {
        return 'msg91.whatsapp';
    }

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return (string) config('services.whatsapp.key') !== ''
            && (string) config('services.whatsapp.number') !== '';
    }

    public function send(OutboundMessage $message): TransportResult
    {
        if (! $this->isConfigured()) {
            return TransportResult::unavailable(
                $this->name(),
                $this->channel(),
                'MSG91 has no authkey or no integrated number.',
            );
        }

        if (! $message->template) {
            /*
             * WhatsApp will not carry text we wrote. Outside a conversation the
             * customer started, only a template Meta has approved may be sent,
             * so a message with no template name has nothing to travel in.
             *
             * Worth trying elsewhere: a channel that sends plain text can carry
             * this perfectly well.
             */
            return TransportResult::failed(
                $this->name(),
                $this->channel(),
                'No approved template for this message, and WhatsApp will not carry free text.',
            );
        }

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
                        'messaging_product' => 'whatsapp',
                        'type' => 'template',
                        'template' => [
                            'name' => $message->template,
                            'language' => ['code' => 'en', 'policy' => 'deterministic'],
                            'namespace' => (string) config('services.whatsapp.namespace'),
                            'to_and_components' => [[
                                // With the country code, as v1 sent it. A bare
                                // ten digit number is not routable and the
                                // provider silently drops it. An array, because
                                // this endpoint sends one template to many
                                // numbers and a single send is a list of one.
                                'to' => [Messenger::dialable($message->to)],
                                'components' => $this->components($message),
                            ]],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            return TransportResult::failed($this->name(), $this->channel(), $e->getMessage());
        }

        if ($response->failed()) {
            return TransportResult::failed(
                $this->name(),
                $this->channel(),
                'HTTP '.$response->status().': '.$response->body(),
                /*
                 * A rejected template or a refused credential will be refused
                 * identically on the next attempt through this same provider,
                 * but a different channel may still get through - an SMS needs
                 * neither an approved template nor this account.
                 */
                worthRetryingElsewhere: true,
            );
        }

        return TransportResult::sent($this->name(), $this->channel(), $response->json('request_id'));
    }

    /**
     * Meta's component list, rewritten as the object MSG91's Code JSON uses.
     *
     * A body parameter becomes `body_1`, `body_2`, ... and a URL button becomes
     * `button_1`. The value key is `value`, not Meta's `text`, and the button
     * uses `subtype` rather than `sub_type`. Sending the Meta array here is
     * what produced "to_and_components received is Invalid".
     *
     * A template with nothing to fill in sends an empty object, which is what
     * the sample curl shows for a message with no variables.
     *
     * @return array<string, array<string, string>>
     */
    private function components(OutboundMessage $message): array
    {
        $mapped = [];
        $body = 1;
        $button = 1;

        foreach ($message->components as $component) {
            if (($component['type'] ?? '') === 'body') {
                foreach ($component['parameters'] ?? [] as $parameter) {
                    $mapped['body_'.$body++] = [
                        'type' => 'text',
                        'value' => (string) ($parameter['text'] ?? ''),
                    ];
                }
            }

            if (($component['type'] ?? '') === 'button') {
                $parameter = $component['parameters'][0] ?? [];

                $mapped['button_'.$button++] = [
                    'subtype' => (string) ($component['sub_type'] ?? 'url'),
                    'type' => 'text',
                    'value' => (string) ($parameter['text'] ?? ''),
                ];
            }
        }

        return $mapped;
    }
}
