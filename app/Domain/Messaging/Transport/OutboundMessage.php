<?php

namespace App\Domain\Messaging\Transport;

/**
 * One message, described in a way every channel can read.
 *
 * The awkwardness this exists to absorb: WhatsApp and SMS want completely
 * different things from the same message. WhatsApp wants the *name* of a
 * template Meta has approved plus an ordered list of values to drop into it -
 * it will not accept the text itself. SMS wants the text and nothing else, and
 * has never heard of a template.
 *
 * Both are already available at the point a message is sent, so this carries
 * both and lets each transport take what it needs. The alternative - deciding
 * per channel at the call site - would put "which provider are we on" into the
 * twelve places that trigger a message, which is exactly what having a
 * transport is meant to avoid.
 */
final class OutboundMessage
{
    /**
     * @param  string  $to  The recipient, ten digits or with a country code.
     *                      Transports normalise; callers should not.
     * @param  string  $body  The wording, fully rendered. What SMS sends, and
     *                        what is recorded for every channel.
     * @param  string|null  $template  The provider's name for the approved
     *                                 template. WhatsApp only.
     * @param  array<int, array<string, mixed>>  $components  Meta-shaped values
     *                                 for that template. WhatsApp only.
     * @param  string|null  $link  The address behind a button, when there is
     *                             one. A channel with no buttons puts it in the
     *                             text instead - otherwise an SMS asks somebody
     *                             to tap a button that is not there.
     */
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $template = null,
        public readonly array $components = [],
        public readonly ?string $link = null,
    ) {}

    /**
     * Can a channel that only sends text carry this at all?
     *
     * A message whose entire meaning is in an approved template we cannot
     * reproduce would arrive as an empty text. Everything here renders its own
     * body, so this is true today - but it is asked rather than assumed,
     * because the day it stops being true the failure is a blank SMS.
     */
    public function hasReadableBody(): bool
    {
        return trim($this->body) !== '';
    }
}
