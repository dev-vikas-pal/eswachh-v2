<?php

namespace App\Domain\Messaging\Transport;

/**
 * One way of getting a message to a phone.
 *
 * Everything that decides *whether* to message somebody - the once-a-day rule,
 * the delivery gate, the test redirect - stays in Messenger. A transport only
 * knows how to hand one message to one provider and say what came back.
 *
 * That split is what makes changing provider a configuration change rather than
 * a rewrite. It is also what makes the fallback chain possible: WhatsApp and
 * SMS are two implementations of this, and the chain is just a list of them.
 */
interface MessageTransport
{
    /**
     * How this transport is named in configuration and in the log.
     *
     * Provider and channel together - 'twilio.sms', not 'twilio' - because one
     * provider carrying two channels is the normal case here, and "it went via
     * Twilio" is not enough to explain what the customer received.
     */
    public function name(): string;

    /** 'whatsapp' or 'sms'. What the customer sees it arrive as. */
    public function channel(): string;

    /**
     * Are the credentials for this transport actually present?
     *
     * Asked before sending so a half-configured transport is skipped with a
     * reason rather than failing per message. A chain listing a provider nobody
     * has set up should fall through to the next one quietly.
     */
    public function isConfigured(): bool;

    public function send(OutboundMessage $message): TransportResult;
}
