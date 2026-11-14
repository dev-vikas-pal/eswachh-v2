<?php

namespace App\Domain\Messaging;

use App\Models\MessageTemplate;

/**
 * The bridge between how we write a message and how WhatsApp carries one.
 *
 * We write `Dear {name}, your plan for {car} expired on {renew_date}`, because
 * a person editing wording in the office should not have to count arguments.
 * Meta approves templates written positionally - `Dear {{1}}, your plan for
 * {{2}}` - and the values are supplied at send time as an ordered list. The two
 * have to agree exactly: a body approved with three variables and sent with two
 * is rejected outright, and one sent in the wrong order is delivered with the
 * car number where the customer's name should be.
 *
 * So the mapping lives here and nowhere else. The export that is uploaded to
 * MSG91 and the payload Messenger sends at run time are both built from this
 * one object, which is the only way they can be guaranteed to describe the same
 * template. Working them out separately is how a template is approved with the
 * placeholders in one order and sent with them in another - and that failure is
 * invisible on our side, because our own `messages` row renders correctly
 * whatever the provider was told.
 *
 * Nothing here knows about MSG91's file format; that is Msg91Export, built on
 * top of this.
 */
final class ProviderTemplate
{
    /**
     * Every `{placeholder}` we recognise in a body.
     *
     * Deliberately matched by shape rather than against
     * MessageTemplate::availablePlaceholders(): two bodies use values that only
     * their own sender knows - `{round}` on the daily summary and `{reason}` on
     * a missed cleaning - and those are placeholders as far as the provider is
     * concerned even though no generic list offers them.
     */
    private const PLACEHOLDER = '/\{([a-z][a-z0-9_]*)\}/';

    /**
     * @param  array<int, string>  $names  Placeholder names, in the order the
     *                                     provider will expect their values.
     */
    private function __construct(
        public readonly string $namedBody,
        public readonly array $names,
        public readonly ?TemplateButton $button,
    ) {}

    public static function for(MessageTemplate $template): self
    {
        return self::fromBody($template->body, TemplateButton::forKey($template->key));
    }

    public static function fromBody(string $body, ?TemplateButton $button = null): self
    {
        preg_match_all(self::PLACEHOLDER, $body, $matches);

        /*
         * Distinct, and in the order they first appear.
         *
         * A name used twice takes one position and is repeated - `{{1}}` may
         * appear more than once in an approved body - so the count of variables
         * matches the count of values we send, which is what the provider
         * validates.
         */
        $names = array_values(array_unique($matches[1]));

        return new self($body, $names, $button);
    }

    /**
     * The body as Meta wants it approved: `{{1}}`, `{{2}}`, in order.
     */
    public function positionalBody(): string
    {
        $positions = $this->positions();

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $m) => '{{'.$positions[$m[1]].'}}',
            $this->namedBody,
        );
    }

    /**
     * Placeholder name => its 1-based position.
     *
     * @return array<string, int>
     */
    public function positions(): array
    {
        $positions = [];

        foreach ($this->names as $index => $name) {
            $positions[$name] = $index + 1;
        }

        return $positions;
    }

    /**
     * `body_1`, `body_2`, ... - how MSG91 names the body variables.
     *
     * Built from the positions rather than from a range, because range(1, 0)
     * counts downwards and returns [1, 0] - so a template with no placeholders
     * at all was being exported as carrying `body_1` and `body_0`.
     */
    public function variableNames(): array
    {
        return array_values(array_map(
            fn (int $position) => 'body_'.$position,
            $this->positions(),
        ));
    }

    public function hasVariables(): bool
    {
        return $this->names !== [];
    }

    /**
     * The values for one send, in the order the provider expects them.
     *
     * Takes the same associative array MessageTemplate::render() takes, so a
     * caller supplies values once and both the readable body we store and the
     * ordered list the provider receives are built from it.
     *
     * A value that is missing becomes an empty string rather than being
     * dropped. Dropping it would shorten the list and shift every value after
     * it up a position, which delivers a message with the wrong words in the
     * wrong places instead of one with a gap in it.
     *
     * @param  array<string, string|int|float|null>  $values
     * @return array<int, string>
     */
    public function ordered(array $values): array
    {
        return array_map(
            fn (string $name) => (string) ($values[$name] ?? ''),
            $this->names,
        );
    }

    /**
     * The Meta-shaped component list for one send.
     *
     * One entry per component that carries variables. Msg91WhatsAppTransport
     * rewrites this into `body_1` / `button_1` before posting; the other
     * transports read this shape directly. A template with no variables and no
     * button sends an empty list, which is an empty object on the wire - not
     * an omission.
     *
     * @param  array<string, string|int|float|null>  $values
     * @param  string|null  $buttonValue  The dynamic part of a URL button
     * @return array<int, array<string, mixed>>
     */
    public function components(array $values, ?string $buttonValue = null): array
    {
        $components = [];

        if ($this->hasVariables()) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    $this->ordered($values),
                ),
            ];
        }

        /*
         * A URL button's variable is the tail of the address, not the whole of
         * it. The approved template holds `https://site/r/{{1}}` and we supply
         * only the token - sending the full URL would produce `/r/https://...`.
         */
        if ($this->button?->isDynamic() && $buttonValue !== null && $buttonValue !== '') {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                // The button's position in the template, as a string. Meta
                // wants it stringly typed and rejects a bare integer.
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $buttonValue]],
            ];
        }

        return $components;
    }

    /**
     * A plausible value for each placeholder, for the approval sample.
     *
     * Meta reviews a template against an example of it filled in, and rejects
     * one whose example is missing or obviously placeholder text. These are the
     * shapes the real values take - a registration that looks like a
     * registration, a date formatted the way we format dates.
     *
     * @return array<int, string>
     */
    public function example(): array
    {
        return array_map(
            fn (string $name) => self::EXAMPLES[$name] ?? 'Sample',
            $this->names,
        );
    }

    /**
     * @var array<string, string>
     */
    private const EXAMPLES = [
        'name' => 'Rahul Sharma',
        'car' => 'UP16NN1645',
        'amount' => '1,872',
        'renew_date' => '2 Sep 2026',
        'cloths' => '20',
        'business' => 'Eswachh',
        'phone' => '9910582309',
        'site' => 'https://eswachh.in',
        'package' => 'Premium',
        'months' => '3',
        'cloth_plan' => 'yes - Weekly 20',
        'cleaner' => 'Amit Kumar',
        'cleaner_phone' => '9910582310',
        'date' => '7 Sep 2026',
        'count' => '12',
        'message' => 'Our office will be closed on Sunday.',
        'invoice_number' => 'ESW/2026/0184',
        'invoice_link' => 'https://eswachh.in/receipt/example',
        'paid_amount' => '1,872',
        'paid_on' => '7 Sep 2026',
        'method' => 'UPI',
        // Supplied by their own senders rather than by valuesFor().
        'round' => 'UP16NN1645 - cleaned',
        'reason' => 'the car was not at the parking spot',
    ];
}
