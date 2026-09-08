<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sending everything to one number while somebody is testing.
 *
 * There is a real need behind this. The delivery gate is production-only by
 * design, so the only way to watch a message actually arrive is to send a real
 * one - and on the live site that means a real customer's phone.
 *
 * It was first done by assigning a number over the recipient in Messenger, and
 * that is the version that ends up deployed: it cannot be switched off without
 * a release, it is invisible to anyone reading the configuration, and while it
 * is there every customer silently stops being messaged. The suite caught it.
 *
 * These tests are what keeps it caught. The redirect must be off unless it is
 * configured, and it must be the configured number when it is - because the
 * failure in both directions is silent, and the second one means renewal links
 * carrying other people's plan tokens land on one phone.
 */
class TestRecipientTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    /**
     * Nothing is redirected unless somebody asked for it.
     *
     * The important half. A default that redirected would mean no customer is
     * ever messaged, and every screen would still report success.
     */
    public function test_the_customer_is_the_recipient_when_no_test_number_is_set(): void
    {
        config()->set('services.whatsapp.test_recipient', null);

        $message = $this->message();

        $this->assertSame('9876500001', $message->recipient);
    }

    public function test_every_message_goes_to_the_test_number_when_one_is_set(): void
    {
        config()->set('services.whatsapp.test_recipient', '8756966569');

        $message = $this->message();

        $this->assertSame('8756966569', $message->recipient);
    }

    /**
     * Ten digits or twelve, it reaches the same phone.
     *
     * Numbers are stored as ten digits everywhere here, because that is what a
     * customer reads off their own handset. The country code is added on the
     * way to the provider, and the test number goes through exactly the same
     * step - so whichever way it is written in .env, it is routable.
     */
    public function test_the_test_number_is_dialled_with_a_country_code_either_way(): void
    {
        $this->assertSame('918756966569', Messenger::dialable('8756966569'));
        $this->assertSame('918756966569', Messenger::dialable('918756966569'));
    }

    /**
     * The redirect also covers messages addressed somewhere other than the
     * customer - the one telling the office a new plan needs a cleaner goes to
     * a number in Settings, and while testing that must not go out either.
     */
    public function test_a_message_addressed_elsewhere_is_redirected_too(): void
    {
        config()->set('services.whatsapp.test_recipient', '8756966569');

        $message = $this->message(toPhone: '9000012345');

        $this->assertSame('8756966569', $message->recipient);
    }

    private function message(?string $toPhone = null)
    {
        return SectorContext::withoutScope(function () use ($toPhone) {
            $customer = Customer::factory()->create([
                'branch_id' => $this->branch->id,
                'phone' => '9876500001',
            ]);

            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            $subscription = Subscription::factory()->forVehicle($vehicle)->create([
                'duration_id' => Duration::factory()->create()->id,
            ]);

            return app(Messenger::class)->send(
                $subscription,
                MessagePurpose::RenewalOverdue,
                'Your plan has expired.',
                toPhone: $toPhone,
            );
        });
    }
}
