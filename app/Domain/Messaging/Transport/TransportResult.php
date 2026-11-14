<?php

namespace App\Domain\Messaging\Transport;

/**
 * What happened when one transport tried to send one message.
 *
 * Returned rather than thrown, including for failures. A transport that throws
 * cannot be part of a fallback chain without every caller wrapping it, and the
 * whole point of the chain is that WhatsApp failing is an ordinary event that
 * SMS then handles - not an exception.
 */
final class TransportResult
{
    private function __construct(
        public readonly bool $ok,
        /** Which transport this was, for the record: 'twilio.sms' and so on. */
        public readonly string $transport,
        /** 'whatsapp' or 'sms' - what the customer actually received. */
        public readonly string $channel,
        /** The provider's id for the message, for reconciling against their logs. */
        public readonly ?string $providerId = null,
        /** The provider's own words. Never a summary of them. */
        public readonly ?string $error = null,
        /**
         * Is it worth trying the next transport?
         *
         * False for a failure the next one will hit too. A number that is not a
         * number fails identically everywhere, and trying three providers in
         * turn to be told so three times only wastes money and time.
         */
        public readonly bool $worthRetryingElsewhere = true,
    ) {}

    public static function sent(string $transport, string $channel, ?string $providerId = null): self
    {
        return new self(true, $transport, $channel, $providerId);
    }

    public static function failed(
        string $transport,
        string $channel,
        string $error,
        bool $worthRetryingElsewhere = true,
    ): self {
        return new self(false, $transport, $channel, null, $error, $worthRetryingElsewhere);
    }

    /**
     * The transport cannot run at all - no credentials, or switched off.
     *
     * Told apart from a failure because it says nothing about the message. The
     * chain moves on without recording an error against wording that was never
     * offered to anybody.
     */
    public static function unavailable(string $transport, string $channel, string $why): self
    {
        return new self(false, $transport, $channel, null, $why);
    }
}
