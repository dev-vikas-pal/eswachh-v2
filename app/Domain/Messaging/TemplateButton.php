<?php

namespace App\Domain\Messaging;

/**
 * The button under a message that takes somebody straight to paying.
 *
 * Only the three renewal messages carry one, and only they should: a button is
 * a call to action, and putting one under a receipt or a cleaning update trains
 * customers to ignore it on the message where it matters.
 *
 * Why this is in code rather than on the template row
 * ---------------------------------------------------
 * Everything else about a message's wording is editable in the office, because
 * wording is the business's to change. A button is not wording. Its label and
 * its address are part of what Meta approved, and changing either on our side
 * would not change the approved template - it would only make our record
 * disagree with what the provider actually sends, which is the kind of
 * difference nobody notices until a customer quotes a button we never sent.
 *
 * So a button changes the way the approved template changes: edit it here,
 * export, upload to MSG91, wait for approval.
 */
final class TemplateButton
{
    /**
     * Stood in for the token while an address is being built, then replaced.
     *
     * Deliberately not a token-shaped string: the addresses below are built by
     * asking the router for the real route, so whatever goes in has to survive
     * URL generation unchanged and be impossible to confuse with a real token.
     */
    private const PLACEHOLDER = 'TOKENHERE';

    private function __construct(
        /** What the button says. Meta caps this at 25 characters. */
        public readonly string $text,
        /** The named route the button opens. */
        public readonly string $route,
        /** A token-shaped sample, for the approval submission. */
        public readonly string $example,
    ) {}

    /**
     * The button for a template, or null where there is none.
     *
     * Keyed on the template key rather than the purpose, because the key is
     * what the office edits against and what the export is named for.
     */
    public static function forKey(?string $key): ?self
    {
        return match ($key) {
            /*
             * The three renewal messages, and all three deliberately.
             *
             * `renewal_due` has never been sent: there was no approved provider
             * template for a reminder before the date, so the nightly chase
             * skips it and only messages plans that have already expired. It is
             * in this list because these templates are being submitted for
             * approval anyway - once it comes back approved, the one thing that
             * stopped it going out is gone.
             */
            'renewal_due', 'renewal_overdue', 'put_on_hold' => new self(
                text: 'Renew now',
                route: 'renew.link',
                example: 'aB3xK9mQ2p7TnR4wZ6yD',
            ),
            default => null,
        };
    }

    /**
     * Does the address change per customer?
     *
     * Always true today. Kept as a question rather than an assumption because a
     * static button - one address for everybody - is a legitimate kind that
     * carries no variable, and the runtime payload must not claim a parameter
     * for a template that was approved without one.
     */
    public function isDynamic(): bool
    {
        return true;
    }

    /**
     * The address as Meta approves it: the fixed half, then `{{1}}`.
     *
     * The variable is a suffix and only ever a suffix - Meta appends what is
     * sent to the end of this - which is the whole reason the renewal link is a
     * short token on the end of a path rather than a signed URL with a query
     * string on it.
     *
     * Built by asking the router rather than by pasting the path in, so the
     * approved button and the page that answers it cannot drift apart. Moving
     * the route moves the button, and the next export carries the new address.
     */
    public function urlPattern(): string
    {
        return $this->urlFor('{{1}}');
    }

    /** The filled-in address shown to a reviewer. */
    public function exampleUrl(): string
    {
        return $this->urlFor($this->example);
    }

    private function urlFor(string $token): string
    {
        return str_replace(
            self::PLACEHOLDER,
            $token,
            route($this->route, ['token' => self::PLACEHOLDER]),
        );
    }
}
