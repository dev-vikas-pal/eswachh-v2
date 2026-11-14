<?php

namespace App\Domain\Messaging\Transport;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The transports to try, in order, until one of them works.
 *
 * A list rather than a single choice, because the useful arrangement here is
 * "WhatsApp, and SMS if that fails". A customer whose number is not on WhatsApp
 * is not an error to be logged and forgotten - they are a customer who needs
 * telling their cleaning has stopped, and there is another way to reach them.
 *
 * One attempt per transport. No retry loop, deliberately: a provider that
 * refuses a message refuses it for a reason, and hammering it is how a
 * scheduled job that should take a minute takes an hour. The next transport is
 * the retry.
 *
 * Every attempt is logged whether it worked or not, because "it went by SMS"
 * and "WhatsApp was down for three hours" are both things somebody will ask
 * about later, and neither is visible from the message that arrived.
 */
class TransportChain
{
    /**
     * The transports this application knows how to build.
     *
     * Named here rather than discovered, so a typo in configuration is an
     * error at startup instead of a channel that silently never runs.
     *
     * @var array<string, class-string<MessageTransport>>
     */
    private const TRANSPORTS = [
        'msg91.whatsapp' => Msg91WhatsAppTransport::class,
        'twilio.whatsapp' => TwilioWhatsAppTransport::class,
        'twilio.sms' => TwilioSmsTransport::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Send by the first transport that will take it.
     *
     * @param  array<int, string>|null  $only  Override the configured chain.
     *                                         For the sign-in code, which may
     *                                         travel differently from the rest.
     */
    public function send(OutboundMessage $message, ?array $only = null): TransportResult
    {
        $names = $only ?? $this->configured();

        if ($names === []) {
            return TransportResult::failed(
                'none',
                'none',
                'No transport is configured. Set MESSAGING_CHAIN.',
                worthRetryingElsewhere: false,
            );
        }

        $last = null;

        foreach ($names as $index => $name) {
            $transport = $this->make($name);

            if (! $transport->isConfigured()) {
                Log::info('Skipping a transport that is not set up.', [
                    'transport' => $name,
                    'next' => $names[$index + 1] ?? 'nothing',
                ]);

                $last = TransportResult::unavailable($name, $transport->channel(), 'Not configured.');

                continue;
            }

            $result = $transport->send($message);

            if ($result->ok) {
                /*
                 * Said out loud only when it was not the first choice. The
                 * ordinary case is noise; falling back is the thing somebody
                 * needs to know happened, because it changes what the customer
                 * saw - an SMS has no button under it.
                 */
                if ($index > 0) {
                    Log::warning('Sent by a fallback transport.', [
                        'transport' => $result->transport,
                        'channel' => $result->channel,
                        'after' => array_slice($names, 0, $index),
                    ]);
                }

                return $result;
            }

            $last = $result;

            Log::warning('A transport could not send; trying the next.', [
                'transport' => $result->transport,
                'error' => $result->error,
                'next' => $names[$index + 1] ?? 'nothing',
            ]);

            if (! $result->worthRetryingElsewhere) {
                break;
            }
        }

        return $last ?? TransportResult::failed('none', 'none', 'Nothing was attempted.');
    }

    /**
     * The configured order, ignoring anything blank.
     *
     * @return array<int, string>
     */
    public function configured(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) config('messaging.chain', []),
        )));
    }

    public function make(string $name): MessageTransport
    {
        $class = self::TRANSPORTS[$name] ?? throw new InvalidArgumentException(
            "There is no message transport called [{$name}]. Known: ".implode(', ', array_keys(self::TRANSPORTS)).'.'
        );

        return $this->container->make($class);
    }

    /** @return array<int, string> */
    public static function known(): array
    {
        return array_keys(self::TRANSPORTS);
    }
}
