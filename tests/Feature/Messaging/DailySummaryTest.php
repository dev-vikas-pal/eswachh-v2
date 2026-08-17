<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessagePurpose;
use App\Enums\ServiceOutcome;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One message a day, instead of one per tap.
 *
 * Every outcome the cleaner recorded used to message the customer immediately.
 * On an early round that is six in the morning, and a household with two cars
 * was woken twice - which is how a service people are happy with becomes a
 * service people mute.
 */
class DailySummaryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cleaner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
        $this->cleaner = User::factory()->cleaner($this->branch)->create();

        $this->customer = SectorContext::withoutScope(
            fn () => Customer::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vinod'])
        );

        MessageTemplate::create([
            'key' => 'daily_summary',
            'name' => "The day's update",
            'provider_template' => 'eswachh_daily_summary',
            'body' => "Dear {name},\n{round}",
            'status' => true,
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    private function car(string $registration): Vehicle
    {
        return SectorContext::withoutScope(function () use ($registration) {
            $vehicle = Vehicle::factory()->forCustomer($this->customer)->create([
                'registration' => $registration,
                'assigned_cleaner_id' => $this->cleaner->id,
            ]);

            Subscription::factory()->create([
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'vehicle_id' => $vehicle->id,
                'status' => SubscriptionStatus::Active,
            ]);

            return $vehicle;
        });
    }

    private function record(Vehicle $vehicle, ServiceOutcome $outcome): void
    {
        $this->actingAs($this->cleaner)
            ->postJson("/api/v1/round/vehicles/{$vehicle->id}", ['outcome' => $outcome->value])
            ->assertCreated();
    }

    public function test_recording_the_round_no_longer_messages_the_customer(): void
    {
        $this->record($this->car('UP16AA1111'), ServiceOutcome::Cleaned);

        /*
         * The whole point. Nothing here is urgent: a customer cannot act on
         * "your car was cleaned", and being told at six in the morning is the
         * reason people mute a service they otherwise like.
         */
        $this->assertSame(0, Message::query()->count());
    }

    public function test_a_household_with_two_cars_hears_once(): void
    {
        $first = $this->car('UP16AA1111');
        $second = $this->car('UP16AA2222');

        $this->record($first, ServiceOutcome::Cleaned);
        $this->record($second, ServiceOutcome::CarAbsent);

        $this->artisan('eswachh:send-daily-summary')->assertSuccessful();

        $messages = Message::query()->where('purpose', MessagePurpose::DailySummary)->get();

        // One message, both cars. Two messages was the complaint.
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('UP16AA1111: cleaned', $messages->first()->body);
        $this->assertStringContainsString('UP16AA2222: not cleaned', $messages->first()->body);
    }

    public function test_the_reason_a_car_was_missed_is_in_the_customers_words(): void
    {
        $this->record($this->car('UP16AA1111'), ServiceOutcome::AccessDenied);

        $this->artisan('eswachh:send-daily-summary')->assertSuccessful();

        // "Access denied" is a category on a form. This is what somebody wants
        // read out to them - and in the evening they can still move the car.
        $this->assertStringContainsString(
            'could not reach the car',
            Message::query()->where('purpose', MessagePurpose::DailySummary)->value('body'),
        );
    }

    public function test_a_quiet_day_sends_nothing(): void
    {
        $this->car('UP16AA1111');

        $this->artisan('eswachh:send-daily-summary')->assertSuccessful();

        // No round, no message. A daily "nothing happened" is worse than
        // silence.
        $this->assertSame(0, Message::query()->count());
    }

    public function test_running_it_twice_does_not_tell_them_twice(): void
    {
        $this->record($this->car('UP16AA1111'), ServiceOutcome::Cleaned);

        $this->artisan('eswachh:send-daily-summary')->assertSuccessful();
        $this->artisan('eswachh:send-daily-summary')->assertSuccessful();

        // The dedupe key is one per customer per day, which is exactly what
        // this message is - so a retried job is harmless.
        $this->assertSame(1, Message::query()->where('purpose', MessagePurpose::DailySummary)->count());
    }
}
