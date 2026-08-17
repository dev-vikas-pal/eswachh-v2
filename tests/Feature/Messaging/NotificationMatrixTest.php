<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessagePurpose;
use App\Mail\WelcomeToEswachh;
use Illuminate\Support\Facades\Mail;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\ServiceOutcome;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\ClothMovement;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The twelve messages the requirements document sets out.
 *
 * v1 sent all of them; v2 sent three. These tests pin down that each one now
 * goes at the right moment - and, just as importantly, that none of them goes
 * at the wrong one.
 *
 * Nothing is actually delivered from a test run: Messenger refuses outright
 * when running tests, so what is asserted is the record of the attempt.
 */
class NotificationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    private Vehicle $vehicle;

    private User $cleaner;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->seed(MessageTemplateSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->cleaner = User::factory()->cleaner($this->branch)->create(['phone' => '9000000001']);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '9876543210',
        ]);

        $this->vehicle = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'registration' => 'UP16AB1234',
            'assigned_cleaner_id' => $this->cleaner->id,
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_every_message_in_the_document_has_wording(): void
    {
        // The guard against a purpose being added in code with nothing to send.
        foreach (MessagePurpose::cases() as $purpose) {
            $this->assertTrue(
                MessageTemplate::query()->where('key', $purpose->value)->exists(),
                "No template for {$purpose->value}.",
            );
        }
    }

    // ------------------------------------------------------------- [1] & [2]

    public function test_a_first_payment_tells_the_customer_and_the_office(): void
    {
        SiteSettings::put(['admin_notify_phone' => '9111111111']);

        $plan = $this->plan(SubscriptionStatus::Pending);
        $this->capture($plan, PaymentPurpose::Subscription);

        $this->assertSent(MessagePurpose::SubscriptionStarted, '9876543210');
        $this->assertSent(MessagePurpose::SubscriptionStartedAdmin, '9111111111');
    }

    public function test_the_office_is_not_told_when_no_number_is_configured(): void
    {
        // v1 had the number in the source, so it always "worked" and always
        // went to one person's phone. Here it is a setting, and an unset
        // setting means nobody is messaged rather than a crash.
        SiteSettings::put(['admin_notify_phone' => '']);

        $this->capture($this->plan(SubscriptionStatus::Pending), PaymentPurpose::Subscription);

        $this->assertNotSent(MessagePurpose::SubscriptionStartedAdmin);
        $this->assertSent(MessagePurpose::SubscriptionStarted, '9876543210');
    }

    // ------------------------------------------------------------------ [3]

    public function test_a_later_payment_is_a_renewal_not_a_new_plan(): void
    {
        $plan = $this->plan(SubscriptionStatus::Active);
        $this->capture($plan, PaymentPurpose::Subscription);

        $this->assertSent(MessagePurpose::Renewed);
        $this->assertNotSent(MessagePurpose::SubscriptionStarted);
    }

    // ------------------------------------------------------------------ [4]

    public function test_a_cloth_top_up_says_so(): void
    {
        // A bundle at the price actually paid. Without one the credit is
        // refused outright - deliberately, because guessing at a quantity is
        // worse than stopping - and nothing is sent because nothing happened.
        $bundle = \App\Models\ClothBundle::create([
            'name' => 'Twenty cloths',
            'cloth_count' => 20,
            'price_paise' => 74900,
            'status' => true,
        ]);

        $plan = $this->plan(SubscriptionStatus::Active, cloth: true);
        $plan->forceFill(['cloth_bundle_id' => $bundle->id])->save();

        $this->capture($plan->fresh(), PaymentPurpose::ClothTopUp);

        $this->assertSent(MessagePurpose::ClothTopUp);
        $this->assertNotSent(MessagePurpose::Renewed);
    }

    // ------------------------------------------------------------------ [6]

    public function test_assigning_a_cleaner_tells_the_customer_who_is_coming(): void
    {
        $plan = $this->plan(SubscriptionStatus::Active);
        $owner = User::factory()->franchiseOwner($this->branch)->create();
        $other = User::factory()->cleaner($this->branch)->create(['name' => 'Ramesh']);

        $this->actingAs($owner)
            ->postJson('/api/v1/subscriptions/'.$plan->id.'/cleaner', ['cleaner_id' => $other->id])
            ->assertOk();

        $body = $this->assertSent(MessagePurpose::CleanerAssigned)->body;

        $this->assertStringContainsString('Ramesh', $body);
        $this->assertStringContainsString('UP16AB1234', $body);
    }

    public function test_taking_a_cleaner_off_does_not_message_anybody(): void
    {
        $plan = $this->plan(SubscriptionStatus::Active);
        $owner = User::factory()->franchiseOwner($this->branch)->create();

        $this->actingAs($owner)
            ->postJson('/api/v1/subscriptions/'.$plan->id.'/cleaner', ['cleaner_id' => null])
            ->assertOk();

        // An internal move. Telling a customer their car has nobody would
        // alarm them about something the office is mid-way through fixing.
        $this->assertNotSent(MessagePurpose::CleanerAssigned);
    }

    // ------------------------------------------------------------------ [8]

    public function test_the_round_no_longer_messages_the_customer_at_once(): void
    {
        $this->plan(SubscriptionStatus::Active);

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'', [
                'outcome' => ServiceOutcome::Cleaned->value,
            ])
            ->assertCreated();

        /*
         * [8] is still honoured - better than it was - but in the evening.
         *
         * This fired the moment the cleaner tapped, which on an early round is
         * six in the morning, and a household with two cars was woken twice.
         * The day's outcomes now go out together: see DailySummaryTest.
         */
        $this->assertNotSent(MessagePurpose::CleaningDone);
    }

    public function test_a_car_that_was_not_there_still_does_not_claim_it_was_cleaned(): void
    {
        $this->plan(SubscriptionStatus::Active);

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'', [
                'outcome' => ServiceOutcome::CarAbsent->value,
            ])
            ->assertCreated();

        // The complaint that would follow such a message would be justified.
        $this->assertNotSent(MessagePurpose::CleaningDone);
    }
    public function test_a_first_plan_emails_a_welcome_when_there_is_an_address(): void
    {
        Mail::fake();

        $this->customer->forceFill(['email' => 'vinod@example.test'])->save();

        $this->capture($this->plan(SubscriptionStatus::Pending), PaymentPurpose::Subscription);

        // v1 mailed a generated password. There is none here - a code to their
        // number is the way in - so this carries the plan and how to get in.
        Mail::assertSent(WelcomeToEswachh::class, fn ($mail) => $mail->hasTo('vinod@example.test'));
    }

    public function test_no_welcome_email_without_an_address(): void
    {
        Mail::fake();

        $this->customer->forceFill(['email' => null])->save();

        $this->capture($this->plan(SubscriptionStatus::Pending), PaymentPurpose::Subscription);

        // Email is optional on the form and most customers leave it blank,
        // which is why the same information is in the message as well.
        Mail::assertNothingSent();
    }

    public function test_a_renewal_does_not_welcome_them_again(): void
    {
        Mail::fake();

        $this->customer->forceFill(['email' => 'vinod@example.test'])->save();

        // Already running: this payment is a renewal, not a first plan.
        $this->capture($this->plan(SubscriptionStatus::Active), PaymentPurpose::Subscription);

        Mail::assertNothingSent();
    }

    // ----------------------------------------------------------- [9] & [10]

    public function test_cloth_pickup_and_delivery_wait_for_the_evening_too(): void
    {
        $this->plan(SubscriptionStatus::Active, cloth: true, balance: 40);

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'/cloth', [
                'direction' => ClothMovement::PICKUP,
                'cloth_count' => 6,
            ])
            ->assertCreated();

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'/cloth', [
                'direction' => ClothMovement::DELIVERY,
                'cloth_count' => 6,
            ])
            ->assertCreated();

        /*
         * [9] and [10] are collected on the same early visit as the cleaning,
         * so two more messages at dawn is two more reasons to mute us. They go
         * in the evening summary with the rest of the round - see
         * DailySummaryTest.
         */
        $this->assertNotSent(MessagePurpose::ClothPickup);
        $this->assertNotSent(MessagePurpose::ClothDelivery);

        $this->artisan('eswachh:send-daily-summary')->assertSuccessful();

        $body = $this->assertSent(MessagePurpose::DailySummary)->body;

        $this->assertStringContainsString('6 cloth(s) collected', $body);
        $this->assertStringContainsString('6 cloth(s) returned', $body);
    }

    // ----------------------------------------------------------------- [12]

    public function test_a_low_balance_warns_on_delivery_only(): void
    {
        // Two left, under the threshold of five the document asks for.
        $this->plan(SubscriptionStatus::Active, cloth: true, balance: 2);

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'/cloth', [
                'direction' => ClothMovement::PICKUP,
                'cloth_count' => 1,
            ])
            ->assertCreated();

        // The balance is about to change, so warning on a pickup would fire
        // against a number that is already out of date.
        $this->assertNotSent(MessagePurpose::ClothsLow);

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'/cloth', [
                'direction' => ClothMovement::DELIVERY,
                'cloth_count' => 1,
            ])
            ->assertCreated();

        $this->assertSent(MessagePurpose::ClothsLow);
    }

    public function test_the_threshold_the_document_asks_for_is_five(): void
    {
        $this->assertEquals(5, SiteSettings::get('cloth_low_threshold'));
    }

    // ---------------------------------------------------------------- always

    public function test_nothing_is_ever_delivered_from_a_test_run(): void
    {
        $this->capture($this->plan(SubscriptionStatus::Pending), PaymentPurpose::Subscription);

        $message = Message::query()->firstOrFail();

        // v1's test suite messaged real customers. This is the line that stops
        // it happening here, and it is not switchable by configuration.
        $this->assertSame('suppressed', $message->status->value);
        $this->assertSame('Running tests.', $message->suppressed_reason);
    }

    // --------------------------------------------------------------- helpers

    private function plan(SubscriptionStatus $status, bool $cloth = false, int $balance = 0): Subscription
    {
        return Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => $status,
            'cloth_service' => $cloth,
            'cloth_balance' => $balance,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
        ]);
    }

    private function capture(Subscription $plan, PaymentPurpose $purpose): Payment
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $plan->id,
            'purpose' => $purpose,
            'status' => PaymentStatus::Initiated,
            'amount_paise' => 74900,
        ]);

        // The path a cash payment recorded at the office takes.
        $payment->forceFill(['status' => PaymentStatus::Captured, 'paid_at' => now()])->save();

        app(\App\Domain\Billing\RecordPayment::class)->extendAfterReconciliation($payment->fresh());

        return $payment->fresh();
    }

    private function assertSent(MessagePurpose $purpose, ?string $to = null): Message
    {
        $message = Message::query()->where('purpose', $purpose)->first();

        $this->assertNotNull($message, "Nothing was sent for {$purpose->value}.");

        if ($to !== null) {
            $this->assertSame($to, $message->recipient);
        }

        return $message;
    }

    private function assertNotSent(MessagePurpose $purpose): void
    {
        $this->assertFalse(
            Message::query()->where('purpose', $purpose)->exists(),
            "A message was sent for {$purpose->value} and should not have been.",
        );
    }
}
