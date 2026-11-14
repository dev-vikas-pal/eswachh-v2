<?php

namespace Tests\Feature\Messaging;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Creating an SMS template from the wording screen.
 *
 * The WhatsApp name has to survive the call. It is what MSG91 sends, and
 * writing the Twilio id into it would point WhatsApp at a template that is
 * not a WhatsApp template.
 */
class SmsTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();
    }

    public function test_creating_an_sms_template_stores_the_twilio_id_and_leaves_whatsapp_alone(): void
    {
        Http::fake([
            'https://content.twilio.com/v1/Content' => Http::response([
                'sid' => 'HXbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ], 201),
        ]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');

        $template = MessageTemplate::create([
            'key' => 'cleaning_done',
            'name' => 'Cleaning done',
            'provider_template' => 'v2_cleaning_done',
            'body' => 'Dear {name}, cleaning has been done for {car}.',
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/message-templates/'.$template->id.'/sms')
            ->assertOk()
            ->assertJsonPath('data.sms_content_sid', 'HXbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $template->refresh();

        $this->assertSame('v2_cleaning_done', $template->provider_template);
        $this->assertSame('Dear {name}, cleaning has been done for {car}.', $template->body);
        $this->assertSame('HXbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $template->sms_content_sid);

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('en', $body['language']);
            $this->assertSame(
                'Dear {{1}}, cleaning has been done for {{2}}.',
                $body['types']['twilio/text']['body'],
            );
            $this->assertSame('Rahul Sharma', $body['variables']['1']);
            $this->assertSame('UP16NN1645', $body['variables']['2']);

            return true;
        });
    }

    public function test_a_refusal_from_twilio_does_not_change_the_wording(): void
    {
        Http::fake([
            'https://content.twilio.com/v1/Content' => Http::response([
                'code' => 20003,
                'message' => 'This feature is not available on a Trial account.',
            ], 401),
        ]);

        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token');

        $template = MessageTemplate::create([
            'key' => 'cleaning_done',
            'name' => 'Cleaning done',
            'provider_template' => 'v2_cleaning_done',
            'body' => 'Dear {name}.',
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/message-templates/'.$template->id.'/sms')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Twilio refused the template (HTTP 401): This feature is not available on a Trial account.');

        $this->assertNull($template->refresh()->sms_content_sid);
        $this->assertSame('v2_cleaning_done', $template->provider_template);
    }

    public function test_an_existing_sid_can_be_saved_on_the_wording(): void
    {
        $template = MessageTemplate::create([
            'key' => 'cleaner_assigned',
            'name' => 'Cleaner assigned',
            'provider_template' => 'v2_cleaner_assigned',
            'body' => 'Dear {name}, a cleaner has been assigned to {car}.',
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson('/api/v1/masters/message-templates/'.$template->id, [
                'name' => 'Cleaner assigned',
                'body' => 'Dear {name}, a cleaner has been assigned to {car}.',
                'provider_template' => 'v2_cleaner_assigned',
                'sms_content_sid' => 'HXfe5ab5f00277942d4d4200328b4d403c',
                'status' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.sms_content_sid', 'HXfe5ab5f00277942d4d4200328b4d403c');

        $template->refresh();

        $this->assertSame('v2_cleaner_assigned', $template->provider_template);
        $this->assertSame('HXfe5ab5f00277942d4d4200328b4d403c', $template->sms_content_sid);
    }
}
