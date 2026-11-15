<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Transport\MessageTransport;
use App\Models\MessageTemplate;
use App\Domain\Messaging\Transport\Msg91WhatsAppTransport;
use App\Domain\Messaging\Transport\OutboundMessage;
use App\Domain\Messaging\Transport\TransportChain;
use App\Domain\Messaging\Transport\TransportResult;
use App\Domain\Messaging\Transport\TwilioSmsTransport;
use App\Domain\Messaging\Transport\TwilioWhatsAppTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trying one way of reaching somebody, then the next.
 *
 * The chain exists because a customer whose number is not on WhatsApp is not an
 * error to be logged - they are somebody whose cleaning has stopped and who
 * still needs telling. What these pin is the part that is easy to get subtly
 * wrong: the order, when to stop, and what gets recorded about which channel
 * actually carried it.
 *
 * Nothing here reaches the network. The transports are either doubles bound
 * into the container, or real ones with Http::fake() underneath, so a mistake
 * in this file cannot message anybody.
 */
class TransportChainTest extends TestCase
{
    // One test writes a template. Without this it stayed in the database and
    // every later run failed on the unique key.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Any real outbound call is a failure in this file.
        Http::preventStrayRequests();
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            to: '9876500001',
            body: 'Cleaning for UP16AB1234 has been paused.',
            template: 'v2_put_on_hold',
            components: [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Rahul']]]],
            link: 'https://eswachh.in/r/aB3xK9mQ2p7TnR4wZ6yD',
        );
    }

    /**
     * A double standing in for one provider.
     *
     * Bound against the real class name, so the chain's own lookup is exercised
     * rather than side-stepped - a test that bypassed make() would pass with the
     * mapping broken.
     */
    private function fake(string $channel, bool $configured, ?TransportResult $result, string $name): MessageTransport
    {
        return new class($channel, $configured, $result, $name) implements MessageTransport
        {
            public int $attempts = 0;

            public function __construct(
                private string $channel,
                private bool $configured,
                private ?TransportResult $result,
                private string $name,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function channel(): string
            {
                return $this->channel;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(OutboundMessage $message): TransportResult
            {
                $this->attempts++;

                return $this->result ?? TransportResult::sent($this->name, $this->channel);
            }
        };
    }

    public function test_the_first_transport_that_works_is_the_one_used(): void
    {
        $whatsapp = $this->fake('whatsapp', true, null, 'twilio.whatsapp');
        $sms = $this->fake('sms', true, null, 'twilio.sms');

        $this->app->instance(TwilioWhatsAppTransport::class, $whatsapp);
        $this->app->instance(TwilioSmsTransport::class, $sms);

        $result = $this->app->make(TransportChain::class)
            ->send($this->message(), ['twilio.whatsapp', 'twilio.sms']);

        $this->assertTrue($result->ok);
        $this->assertSame('whatsapp', $result->channel);
        // The fallback is never troubled when the first choice works.
        $this->assertSame(0, $sms->attempts);
    }

    /**
     * The whole point: WhatsApp fails, SMS carries it.
     */
    public function test_a_failure_falls_through_to_the_next_transport(): void
    {
        $whatsapp = $this->fake(
            'whatsapp',
            true,
            TransportResult::failed('twilio.whatsapp', 'whatsapp', 'HTTP 400: not a WhatsApp user'),
            'twilio.whatsapp',
        );
        $sms = $this->fake('sms', true, null, 'twilio.sms');

        $this->app->instance(TwilioWhatsAppTransport::class, $whatsapp);
        $this->app->instance(TwilioSmsTransport::class, $sms);

        $result = $this->app->make(TransportChain::class)
            ->send($this->message(), ['twilio.whatsapp', 'twilio.sms']);

        $this->assertTrue($result->ok);
        // Recorded as SMS, which matters: that message arrived with no button.
        $this->assertSame('sms', $result->channel);
        $this->assertSame(1, $sms->attempts);
    }

    /**
     * A failure the next transport will hit too stops the chain.
     *
     * A number that is not a number fails identically everywhere, and asking
     * three providers in turn to be told so three times costs money and time
     * for an answer already known.
     */
    public function test_a_hopeless_failure_does_not_try_the_rest(): void
    {
        $whatsapp = $this->fake(
            'whatsapp',
            true,
            TransportResult::failed('twilio.whatsapp', 'whatsapp', 'Not a number', worthRetryingElsewhere: false),
            'twilio.whatsapp',
        );
        $sms = $this->fake('sms', true, null, 'twilio.sms');

        $this->app->instance(TwilioWhatsAppTransport::class, $whatsapp);
        $this->app->instance(TwilioSmsTransport::class, $sms);

        $result = $this->app->make(TransportChain::class)
            ->send($this->message(), ['twilio.whatsapp', 'twilio.sms']);

        $this->assertFalse($result->ok);
        $this->assertSame(0, $sms->attempts);
    }

    /**
     * A transport nobody has set up is skipped, not failed.
     *
     * It says nothing about the message, so it must not leave an error recorded
     * against wording that was never offered to anybody.
     */
    public function test_an_unconfigured_transport_is_stepped_over(): void
    {
        $whatsapp = $this->fake('whatsapp', false, null, 'twilio.whatsapp');
        $sms = $this->fake('sms', true, null, 'twilio.sms');

        $this->app->instance(TwilioWhatsAppTransport::class, $whatsapp);
        $this->app->instance(TwilioSmsTransport::class, $sms);

        $result = $this->app->make(TransportChain::class)
            ->send($this->message(), ['twilio.whatsapp', 'twilio.sms']);

        $this->assertTrue($result->ok);
        $this->assertSame('sms', $result->channel);
        $this->assertSame(0, $whatsapp->attempts);
    }

    public function test_an_empty_chain_fails_rather_than_pretending_to_send(): void
    {
        config()->set('messaging.chain', []);

        $result = $this->app->make(TransportChain::class)->send($this->message());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('No transport is configured', (string) $result->error);
    }

    public function test_an_unknown_transport_name_is_refused_loudly(): void
    {
        $this->expectExceptionMessage('There is no message transport called [carrier.pigeon]');

        $this->app->make(TransportChain::class)->send($this->message(), ['carrier.pigeon']);
    }

    /** The default keeps every existing deployment sending exactly as it did. */
    public function test_the_default_chain_is_the_provider_everything_has_used_so_far(): void
    {
        $this->assertSame(['msg91.whatsapp'], (new TransportChain($this->app))->configured());
    }

    public function test_msg91_posts_the_template_and_its_components(): void
    {
        Http::fake(['*' => Http::response(['request_id' => 'req-1'], 200)]);

        config()->set('services.whatsapp.key', 'a-real-looking-key');
        config()->set('services.whatsapp.number', '919910582309');
        config()->set('services.whatsapp.namespace', 'e73e5b95_f483_40b7_b637_5bf1d90ba813');

        $result = $this->app->make(Msg91WhatsAppTransport::class)->send(new OutboundMessage(
            to: '9876500001',
            body: 'Dear Rahul, your plan for UP16 expires on 2 Sep.',
            template: 'v2_renewal_due_subs',
            components: [
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => 'Rahul'],
                    ['type' => 'text', 'text' => 'UP16'],
                    ['type' => 'text', 'text' => '2 Sep'],
                ]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [
                    ['type' => 'text', 'text' => 'aB3xK9mQ2p7TnR4wZ6yD'],
                ]],
            ],
        ));

        $this->assertTrue($result->ok);
        $this->assertSame('req-1', $result->providerId);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $payload = $body['payload'];
            $template = $payload['template'];
            $entry = $template['to_and_components'][0];

            $this->assertSame('919910582309', $body['integrated_number']);
            $this->assertSame('template', $body['content_type']);
            $this->assertSame('whatsapp', $payload['messaging_product']);
            $this->assertArrayNotHasKey('to', $payload);
            $this->assertSame('v2_renewal_due_subs', $template['name']);
            $this->assertSame('e73e5b95_f483_40b7_b637_5bf1d90ba813', $template['namespace']);
            // The country code is added here, not by the caller.
            $this->assertSame(['919876500001'], $entry['to']);
            $this->assertSame([
                'body_1' => ['type' => 'text', 'value' => 'Rahul'],
                'body_2' => ['type' => 'text', 'value' => 'UP16'],
                'body_3' => ['type' => 'text', 'value' => '2 Sep'],
                'button_1' => ['subtype' => 'url', 'type' => 'text', 'value' => 'aB3xK9mQ2p7TnR4wZ6yD'],
            ], $entry['components']);

            return true;
        });
    }

    /**
     * Twilio addresses a template by its own Content SID, not by the name Meta
     * approved, and takes the values as a positional object.
     */
    public function test_twilio_whatsapp_sends_a_content_sid_and_positional_variables(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM123'], 201)]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');
        config()->set('services.twilio.whatsapp_from', '919910582309');
        config()->set('messaging.twilio.content.v2_put_on_hold', 'HX123');

        $result = $this->app->make(TwilioWhatsAppTransport::class)->send($this->message());

        $this->assertTrue($result->ok);
        $this->assertSame('SM123', $result->providerId);

        Http::assertSent(function ($request) {
            $this->assertSame('whatsapp:+919876500001', $request['To']);
            $this->assertSame('HX123', $request['ContentSid']);
            $this->assertSame('{"1":"Rahul"}', $request['ContentVariables']);

            return true;
        });
    }

    /**
     * A template with no SID mapped is named in the error.
     *
     * Otherwise the failure is a Twilio error code about a missing content
     * resource, which does not point at the configuration that is missing.
     */
    public function test_twilio_whatsapp_says_which_template_has_no_content_sid(): void
    {
        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');
        config()->set('services.twilio.whatsapp_from', '919910582309');
        config()->set('messaging.twilio.content', []);

        $result = $this->app->make(TwilioWhatsAppTransport::class)->send($this->message());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('v2_put_on_hold', (string) $result->error);
        $this->assertStringContainsString('TWILIO_CONTENT_PUT_ON_HOLD', (string) $result->error);
    }

    /**
     * This account's MSG91 name has an extra _subs. The Content SID is keyed
     * on the template's own name, and both have to resolve.
     */
    public function test_twilio_whatsapp_finds_a_content_sid_for_the_account_template_name(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM123'], 201)]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');
        config()->set('services.twilio.whatsapp_from', '17372508034');
        config()->set('messaging.twilio.content.v2_put_on_hold', 'HX123');

        $message = $this->message();
        $result = $this->app->make(TwilioWhatsAppTransport::class)->send(new OutboundMessage(
            to: $message->to,
            body: $message->body,
            template: 'v2_put_on_hold_subs',
            components: $message->components,
            link: $message->link,
        ));

        $this->assertTrue($result->ok);
        Http::assertSent(fn ($request) => $request['ContentSid'] === 'HX123'
            && $request['From'] === 'whatsapp:+17372508034');
    }

    /**
     * SMS has no buttons, so the link goes in the text.
     *
     * Without this the message asks somebody to tap a button that is not there
     * and gives them no other way to pay.
     */
    public function test_sms_puts_the_button_link_into_the_body(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM999'], 201)]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');
        config()->set('services.twilio.sms_from', '+12015550123');

        $result = $this->app->make(TwilioSmsTransport::class)->send($this->message());

        $this->assertTrue($result->ok);

        Http::assertSent(function ($request) {
            $this->assertSame('+919876500001', $request['To']);
            $this->assertStringContainsString('https://eswachh.in/r/aB3xK9mQ2p7TnR4wZ6yD', $request['Body']);
            $this->assertArrayNotHasKey('ContentSid', $request->data());

            return true;
        });
    }

    /**
     * Once the office has created an SMS template, that send uses it.
     * Wording without one still goes as plain text, which is the test above.
     */
    public function test_sms_uses_the_content_sid_when_the_office_has_created_one(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM999'], 201)]);

        MessageTemplate::create([
            'key' => 'sms_probe',
            'name' => 'SMS probe',
            'provider_template' => 'v2_sms_probe',
            'body' => 'Hello {name}.',
            'sms_content_sid' => 'HXaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');
        config()->set('services.twilio.sms_from', '+12015550123');

        $result = $this->app->make(TwilioSmsTransport::class)->send(new OutboundMessage(
            to: '9876500001',
            body: 'Hello Rahul.',
            template: 'v2_sms_probe',
            components: [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Rahul']]]],
        ));

        $this->assertTrue($result->ok);

        Http::assertSent(function ($request) {
            $this->assertSame('HXaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $request['ContentSid']);
            $this->assertArrayNotHasKey('Body', $request->data());
            $this->assertSame('{"1":"Rahul"}', $request['ContentVariables']);

            return true;
        });
    }

    /**
     * India's regulator requires the registered ids on every message, and
     * Twilio passes them through. Without registration the carrier drops the
     * message after Twilio has accepted it.
     */
    public function test_sms_carries_the_dlt_identifiers_when_they_are_set(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM999'], 201)]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');
        config()->set('services.twilio.sms_from', 'ESWACH');
        config()->set('services.twilio.dlt.entity_id', 'ENTITY1');
        config()->set('services.twilio.dlt.template_id', 'TEMPLATE1');

        $this->app->make(TwilioSmsTransport::class)->send($this->message());

        Http::assertSent(function ($request) {
            $this->assertSame('ENTITY1', $request['DltEntityId']);
            $this->assertSame('TEMPLATE1', $request['DltTemplateId']);

            return true;
        });
    }

    /** Two segments is two messages billed, so the wording's length is a cost. */
    public function test_segment_counting_reflects_what_will_be_billed(): void
    {
        $this->assertSame(1, TwilioSmsTransport::segments(str_repeat('a', 160)));
        $this->assertSame(2, TwilioSmsTransport::segments(str_repeat('a', 161)));
    }
}
