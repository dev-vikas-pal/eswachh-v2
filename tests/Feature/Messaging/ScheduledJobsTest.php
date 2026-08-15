<?php

namespace Tests\Feature\Messaging;

use App\Domain\Cloth\ClothLedger;
use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\ClothBundle;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The nightly jobs, and the guard that stops them messaging real people.
 */
class ScheduledJobsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->branch = Branch::factory()->create();

        // Any outbound call at all is a failure here: nothing in a test run
        // should ever reach a phone.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_nothing_is_ever_delivered_from_a_test_run(): void
    {
        // Configured as if it were fully live. It still must not send.
        config()->set('services.whatsapp.enabled', true);
        config()->set('services.whatsapp.key', 'real-looking-key');

        $messenger = app(Messenger::class);

        $this->assertFalse($messenger->deliveryEnabled());
        $this->assertSame('Running tests.', $messenger->suppressionReason());

        BranchContext::withoutScope(function () use ($messenger) {
            $subscription = $this->subscription();

            $message = $messenger->send($subscription, MessagePurpose::RenewalDue, 'Your plan is due.');

            // Recorded in full, delivered to nobody. v1's test suite messaged
            // real customers because a single config flag was the only guard.
            $this->assertSame(MessageStatus::Suppressed, $message->status);
            $this->assertSame('Your plan is due.', $message->body);
            $this->assertSame('Running tests.', $message->suppressed_reason);
        });
    }

    public function test_a_customer_is_not_told_the_same_thing_twice_in_a_day(): void
    {
        BranchContext::withoutScope(function () {
            $messenger = app(Messenger::class);
            $subscription = $this->subscription();

            $first = $messenger->send($subscription, MessagePurpose::RenewalDue, 'Due soon.');
            $second = $messenger->send($subscription, MessagePurpose::RenewalDue, 'Due soon.');

            $this->assertNotNull($first);
            // Null, not an exception: there was simply nothing to do.
            $this->assertNull($second);
            $this->assertSame(1, Message::query()->count());
        });
    }

    public function test_a_different_purpose_on_the_same_day_still_goes(): void
    {
        BranchContext::withoutScope(function () {
            $messenger = app(Messenger::class);
            $subscription = $this->subscription();

            $messenger->send($subscription, MessagePurpose::RenewalDue, 'Due soon.');
            $messenger->send($subscription, MessagePurpose::PutOnHold, 'Paused.');

            // The rule is one per thing being said, not one per day.
            $this->assertSame(2, Message::query()->count());
        });
    }

    public function test_reminders_go_out_on_the_offsets_and_no_others(): void
    {
        BranchContext::withoutScope(function () {
            // Due in 7 days, 5 days, 3 days, today, and 3 days overdue.
            foreach ([7, 5, 3, 0, -3] as $days) {
                $this->subscription(['period_end' => Carbon::today()->addDays($days)]);
            }

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            // Four of the five: the one due in five days hears nothing, because
            // a customer messaged every day stops reading the messages.
            $this->assertSame(4, Message::query()->count());
        });
    }

    public function test_an_overdue_reminder_says_something_different(): void
    {
        BranchContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()->subDays(3)]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            $message = Message::query()->firstOrFail();
            $this->assertSame(MessagePurpose::RenewalOverdue, $message->purpose);
            $this->assertStringContainsString('overdue', $message->body);
        });
    }

    public function test_a_subscription_on_hold_is_not_chased(): void
    {
        BranchContext::withoutScope(function () {
            $this->subscription([
                'period_end' => Carbon::today(),
                'status' => SubscriptionStatus::Hold,
            ]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            // They already know. Chasing a paused customer for a renewal is how
            // a reminder system loses its credibility.
            $this->assertSame(0, Message::query()->count());
        });
    }

    public function test_running_the_reminder_job_twice_messages_once(): void
    {
        BranchContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();
            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            $this->assertSame(1, Message::query()->count());
        });
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        BranchContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()]);

            $this->artisan('eswachh:send-renewal-reminders --dry-run')->assertSuccessful();

            $this->assertSame(0, Message::query()->count());
        });
    }

    public function test_holding_respects_the_grace_period(): void
    {
        BranchContext::withoutScope(function () {
            $justOverdue = $this->subscription(['period_end' => Carbon::today()->subDays(3)]);
            $longOverdue = $this->subscription(['period_end' => Carbon::today()->subDays(20)]);

            $this->artisan('eswachh:hold-overdue --grace=7')->assertSuccessful();

            // Three days late is not a reason to stop cleaning somebody's car.
            $this->assertSame(SubscriptionStatus::Active, $justOverdue->fresh()->status);
            $this->assertSame(SubscriptionStatus::Hold, $longOverdue->fresh()->status);
        });
    }

    public function test_holding_refuses_to_run_beyond_the_safety_limit(): void
    {
        BranchContext::withoutScope(function () {
            collect(range(1, 5))->each(
                fn () => $this->subscription(['period_end' => Carbon::today()->subDays(30)])
            );

            $this->artisan('eswachh:hold-overdue --grace=7 --limit=3')->assertFailed();

            // Nothing at all, not the first three. A bad date must not pause
            // half the book before anybody notices.
            $this->assertSame(
                0,
                Subscription::query()->where('status', SubscriptionStatus::Hold)->count()
            );
        });
    }

    public function test_a_paused_subscription_has_its_cloths_written_off(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription([
                'period_end' => Carbon::today()->subDays(20),
                'cloth_service' => true,
            ]);

            $bundle = ClothBundle::create([
                'name' => '50 Cloths', 'cloth_count' => 50, 'price_paise' => 40000, 'status' => true,
            ]);
            app(ClothLedger::class)->purchase($subscription, $bundle);

            $this->artisan('eswachh:hold-overdue --grace=7')->assertSuccessful();

            $subscription->refresh();
            $this->assertSame(SubscriptionStatus::Hold, $subscription->status);
            // Zeroed through the ledger, so it can be explained if they renew.
            $this->assertSame(0, $subscription->cloth_balance);
            $this->assertSame(
                0,
                app(ClothLedger::class)->balanceFromLedger($subscription)
            );
        });
    }

    public function test_a_paused_customer_is_told(): void
    {
        BranchContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()->subDays(20)]);

            $this->artisan('eswachh:hold-overdue --grace=7')->assertSuccessful();

            $message = Message::query()->firstOrFail();
            $this->assertSame(MessagePurpose::PutOnHold, $message->purpose);
            $this->assertStringContainsString('paused', $message->body);
        });
    }

    public function test_the_hold_dry_run_changes_nothing(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['period_end' => Carbon::today()->subDays(20)]);

            $this->artisan('eswachh:hold-overdue --grace=7 --dry-run')->assertSuccessful();

            $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
            $this->assertSame(0, Message::query()->count());
        });
    }

    public function test_the_jobs_cover_every_branch(): void
    {
        $other = Branch::factory()->create();

        BranchContext::withoutScope(function () use ($other) {
            $this->subscription(['period_end' => Carbon::today()]);
            $this->subscription(['period_end' => Carbon::today()], $other);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            // A nightly job that only served one franchise would be worse than
            // none at all.
            $this->assertSame(2, Message::query()->count());
        });
    }

    private function subscription(array $attributes = [], ?Branch $branch = null): Subscription
    {
        $branch ??= $this->branch;

        $customer = Customer::factory()->create(['branch_id' => $branch->id]);
        $vehicle = Vehicle::factory()->forCustomer($customer)->create();

        return Subscription::factory()->forVehicle($vehicle)->create(array_merge([
            'duration_id' => Duration::factory()->create()->id,
        ], $attributes));
    }
}
