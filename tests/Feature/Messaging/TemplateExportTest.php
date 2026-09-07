<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Msg91Export;
use App\Domain\Messaging\ProviderTemplate;
use App\Models\MessageTemplate;
use App\Support\Tenancy\SectorContext;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bridge between our wording and what MSG91 approves.
 *
 * Two things are being guarded, and neither shows up anywhere a person would
 * look. A template uploaded with its variables in one order and sent with them
 * in another is delivered with the car number where the customer's name should
 * be - and our own `messages` row still reads perfectly, because it was
 * rendered from the named body. And a template whose variable count does not
 * match what is sent is rejected by the provider outright, which looks from
 * here like messages simply not arriving.
 *
 * So these tests pin the two halves against each other rather than against
 * fixed strings: whatever the office writes, the export and the send have to
 * agree about it.
 */
class TemplateExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();
    }

    public function test_named_placeholders_become_positional_in_first_appearance_order(): void
    {
        $provider = ProviderTemplate::fromBody("Dear {name},\nYour car {car} expired on {renew_date}.");

        $this->assertSame(['name', 'car', 'renew_date'], $provider->names);
        $this->assertSame(
            "Dear {{1}},\nYour car {{2}} expired on {{3}}.",
            $provider->positionalBody(),
        );
    }

    /**
     * A name used twice takes one position and is repeated.
     *
     * The alternative - a fresh number for each occurrence - would mean sending
     * the same value twice, and the count of variables would no longer match
     * the count of distinct things the template is about.
     */
    public function test_a_repeated_placeholder_keeps_one_position(): void
    {
        $provider = ProviderTemplate::fromBody('Hello {name}, we mean you, {name}. Car {car}.');

        $this->assertSame(['name', 'car'], $provider->names);
        $this->assertSame('Hello {{1}}, we mean you, {{1}}. Car {{2}}.', $provider->positionalBody());
    }

    /**
     * The order values are sent in must follow the order they were approved in.
     *
     * This is the one that matters. Everything else about a wrong export is
     * loud; this one delivers a plausible-looking message with the values in
     * the wrong places.
     */
    public function test_values_are_ordered_to_match_the_positions_they_were_approved_as(): void
    {
        $provider = ProviderTemplate::fromBody('Car {car} for {name}, Rs {amount}.');

        // Deliberately supplied in a different order from the body.
        $ordered = $provider->ordered([
            'amount' => '1,872',
            'name' => 'Rahul',
            'car' => 'UP16NN1645',
        ]);

        $this->assertSame(['UP16NN1645', 'Rahul', '1,872'], $ordered);
    }

    /**
     * A value nobody supplied is sent empty rather than dropped.
     *
     * Dropping it shortens the list and shifts every value after it up a
     * position, which turns one missing word into a message that is wrong
     * throughout.
     */
    public function test_a_missing_value_holds_its_position(): void
    {
        $provider = ProviderTemplate::fromBody('{name} owes {amount} for {car}.');

        $this->assertSame(['Rahul', '', 'UP16NN1645'], $provider->ordered([
            'name' => 'Rahul',
            'car' => 'UP16NN1645',
        ]));
    }

    public function test_the_export_carries_the_body_variables_and_an_example_of_each(): void
    {
        $template = new MessageTemplate([
            'key' => 'renewed',
            'provider_template' => 'v2_renewed',
            'body' => 'Dear {name}, your plan for {car} is renewed.',
        ]);

        $export = (new Msg91Export($template))->toArray();
        $language = $export['languages'][0];

        $this->assertSame('v2_renewed', $export['name']);
        $this->assertSame('UTILITY', $export['category']);
        $this->assertSame('POSITIONAL', $language['parameter_format']);
        $this->assertSame(['body_1', 'body_2'], $language['variables']);
        $this->assertSame(
            ['body_1' => ['type' => 'text'], 'body_2' => ['type' => 'text']],
            $language['variable_type'],
        );

        $body = $language['code'][0];
        $this->assertSame('BODY', $body['type']);
        $this->assertSame('Dear {{1}}, your plan for {{2}} is renewed.', $body['text']);
        // One example per variable, or Meta rejects the submission.
        $this->assertCount(2, $body['example']['body_text'][0]);
    }

    /**
     * Nothing MSG91 assigns is claimed by a file that is being uploaded to
     * create a template.
     */
    public function test_the_export_claims_no_provider_assigned_identifiers(): void
    {
        $export = (new Msg91Export(new MessageTemplate([
            'key' => 'renewed',
            'provider_template' => 'v2_renewed',
            'body' => 'Dear {name}.',
        ])))->toArray();

        $language = $export['languages'][0];

        $this->assertNull($language['id']);
        $this->assertNull($language['msg91_template_id']);
        $this->assertSame('pending', $language['status']);
    }

    public function test_a_body_with_no_placeholders_carries_no_example(): void
    {
        $export = (new Msg91Export(new MessageTemplate([
            'key' => 'custom',
            'provider_template' => 'v2_custom',
            'body' => 'The office is closed on Sunday.',
        ])))->toArray();

        $body = $export['languages'][0]['code'][0];

        $this->assertSame([], $export['languages'][0]['variables']);
        $this->assertArrayNotHasKey('example', $body);
    }

    /**
     * The three renewal messages carry a Renew now button, and nothing else
     * does.
     */
    public function test_only_the_renewal_messages_carry_a_pay_button(): void
    {
        $withButton = ['renewal_due', 'renewal_overdue', 'put_on_hold'];

        $this->seed(MessageTemplateSeeder::class);

        foreach (MessageTemplate::all() as $template) {
            $code = (new Msg91Export($template))->toArray()['languages'][0]['code'];
            $types = array_column($code, 'type');

            $this->assertSame(
                in_array($template->key, $withButton, true),
                in_array('BUTTONS', $types, true),
                "Wrong button state on {$template->key}",
            );
        }
    }

    /**
     * The button's address is a fixed half plus `{{1}}`, and the variable is
     * only ever the tail. Meta appends what is sent to the end of the approved
     * URL, so a token has to sit on the end of a path with no query string.
     */
    public function test_the_button_url_is_the_renew_route_with_a_trailing_variable(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        $template = MessageTemplate::query()->where('key', 'renewal_overdue')->firstOrFail();
        $code = (new Msg91Export($template))->toArray()['languages'][0]['code'];

        $buttons = collect($code)->firstWhere('type', 'BUTTONS')['buttons'];

        $this->assertCount(1, $buttons);
        $this->assertSame('URL', $buttons[0]['type']);
        $this->assertSame('Renew now', $buttons[0]['text']);
        $this->assertSame(url('/r/').'/{{1}}', $buttons[0]['url']);
        // A filled-in address for the reviewer, on the same route.
        $this->assertStringStartsWith(url('/r/').'/', $buttons[0]['example'][0]);
        $this->assertStringNotContainsString('{{1}}', $buttons[0]['example'][0]);
    }

    /**
     * Every template the seeder ships is named v2_, and every name is one the
     * provider will accept.
     */
    public function test_every_seeded_template_is_named_for_this_system(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        foreach (MessageTemplate::all() as $template) {
            $this->assertMatchesRegularExpression(
                '/^v2_[a-z0-9_]+$/',
                (string) $template->provider_template,
                "{$template->key} is not named for v2",
            );
        }
    }

    /**
     * The runtime payload and the approved template describe the same variables.
     *
     * Built from the seeded wording rather than a fixture, so this keeps
     * holding as the office edits the words.
     */
    public function test_the_sent_components_match_the_exported_variable_count(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        foreach (MessageTemplate::all() as $template) {
            $provider = ProviderTemplate::for($template);
            $exported = (new Msg91Export($template))->toArray()['languages'][0]['variables'];

            $components = $provider->components(
                array_fill_keys($provider->names, 'x'),
                'aB3xK9mQ2p7TnR4wZ6yD',
            );

            $body = collect($components)->firstWhere('type', 'body');

            $this->assertSame(
                count($exported),
                $body ? count($body['parameters']) : 0,
                "Variable count disagrees on {$template->key}",
            );
        }
    }

    /**
     * A button parameter travels only for a template that was approved with a
     * button. Sending one for a template without would be rejected.
     */
    public function test_a_button_parameter_is_only_sent_for_a_template_that_has_one(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        $withButton = ProviderTemplate::for(
            MessageTemplate::query()->where('key', 'put_on_hold')->firstOrFail()
        );
        $without = ProviderTemplate::for(
            MessageTemplate::query()->where('key', 'payment_receipt')->firstOrFail()
        );

        $token = 'aB3xK9mQ2p7TnR4wZ6yD';

        $this->assertNotNull(
            collect($withButton->components([], $token))->firstWhere('type', 'button'),
        );
        $this->assertNull(
            collect($without->components([], $token))->firstWhere('type', 'button'),
        );
    }
}
