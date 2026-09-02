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
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
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
        SectorContext::reset();

        $this->branch = Branch::factory()->create();

        // Any outbound call at all is a failure here: nothing in a test run
        // should ever reach a phone.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
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

        SectorContext::withoutScope(function () use ($messenger) {
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
        SectorContext::withoutScope(function () {
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
        SectorContext::withoutScope(function () {
            $messenger = app(Messenger::class);
            $subscription = $this->subscription();

            $messenger->send($subscription, MessagePurpose::RenewalDue, 'Due soon.');
            $messenger->send($subscription, MessagePurpose::PutOnHold, 'Paused.');

            // The rule is one per thing being said, not one per day.
            $this->assertSame(2, Message::query()->count());
        });
    }

    public function test_nobody_is_chased_before_their_plan_has_expired(): void
    {
        SectorContext::withoutScope(function () {
            // Due in a week, in three days, and today.
            foreach ([7, 3, 0] as $days) {
                $this->subscription(['period_end' => Carbon::today()->addDays($days)]);
            }

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            /*
             * These three owe nothing yet, and there is no approved provider
             * template that says a renewal is coming up - so a message sent to
             * them has nothing to travel in and would be rejected.
             *
             * This used to send to all three, which was three quarters of every
             * run.
             */
            $this->assertSame(0, Message::query()->count());
        });
    }

    public function test_every_overdue_plan_is_chased_however_long_it_has_been(): void
    {
        SectorContext::withoutScope(function () {
            // One, two, six, nine and sixty-three days past the date.
            foreach ([1, 2, 6, 9, 63] as $days) {
                $this->subscription(['period_end' => Carbon::today()->subDays($days)]);
            }

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            /*
             * All five. The rule used to be fixed offsets - one, three and
             * seven days over - which chased the first of these and abandoned
             * the rest permanently, because the day they would have qualified
             * on had already gone. On a freshly imported database that is most
             * of the book.
             */
            $this->assertSame(5, Message::query()->count());
        });
    }

    public function test_an_overdue_plan_is_chased_every_day_until_it_is_renewed(): void
    {
        SectorContext::withoutScope(function () {
            $plan = $this->subscription(['period_end' => Carbon::today()->subDays(10)]);

            foreach ([0, 1, 2, 3] as $days) {
                $this->artisan('eswachh:send-renewal-reminders --date='.Carbon::today()->addDays($days)->toDateString())
                    ->assertSuccessful();
            }

            $this->assertSame(4, Message::query()->where('subscription_id', $plan->id)->count());
        });
    }

    public function test_the_message_changes_when_the_plan_is_paused_but_the_rhythm_does_not(): void
    {
        SectorContext::withoutScope(function () {
            $plan = $this->subscription(['period_end' => Carbon::today()->subDays(10)]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            // The office pauses it, as it would once the grace period is up.
            $plan->forceFill(['status' => SubscriptionStatus::Hold])->save();

            $this->artisan('eswachh:send-renewal-reminders --date='.Carbon::today()->addDay()->toDateString())
                ->assertSuccessful();

            /*
             * Still daily - what changed is what it says. One asks them to
             * renew before the cleaning stops; the other tells them it already
             * has.
             */
            $purposes = Message::query()
                ->where('subscription_id', $plan->id)
                ->orderBy('sent_on')
                ->pluck('purpose')
                ->all();

            $this->assertSame(
                [MessagePurpose::RenewalOverdue, MessagePurpose::PutOnHold],
                $purposes,
            );
        });
    }

    public function test_a_plan_on_hold_is_chased_every_day(): void
    {
        SectorContext::withoutScope(function () {
            $plan = $this->subscription([
                'period_end' => Carbon::today()->subDays(40),
                'status' => SubscriptionStatus::Hold,
            ]);

            /*
             * The cleaning has actually stopped and the customer notices every
             * morning, so the business chases every day until they renew or say
             * they are finished. That is how it worked before this system, with
             * the owner sending it by hand.
             */
            foreach ([0, 1, 2, 3] as $days) {
                $this->artisan('eswachh:send-renewal-reminders --date='.Carbon::today()->addDays($days)->toDateString())
                    ->assertSuccessful();
            }

            $this->assertSame(4, Message::query()->where('subscription_id', $plan->id)->count());
        });
    }

    public function test_the_pause_delay_is_set_in_the_office(): void
    {
        SectorContext::withoutScope(function () {
            // Three days, not the seven the command used to hard-code.
            SiteSettings::put(['renewal_grace_days' => '3']);

            $this->subscription(['period_end' => Carbon::today()->subDays(4)]);

            $this->artisan('eswachh:hold-overdue')->assertSuccessful();

            /*
             * The box on the Settings screen was read by nothing: the schedule
             * passed --grace=7 and the command defaulted to 7, so it could be
             * set to any number and every plan still paused after a week.
             */
            $this->assertSame(
                SubscriptionStatus::Hold,
                Subscription::query()->firstOrFail()->status,
            );
        });
    }

    public function test_the_two_rhythms_are_set_in_the_office(): void
    {
        SectorContext::withoutScope(function () {
            // Chase overdue plans weekly and paused ones fortnightly - both
            // slower than the daily default, to prove the settings decide.
            SiteSettings::put([
                'reminder_gap_overdue_days' => '7',
                'reminder_gap_hold_days' => '14',
            ]);

            $overdue = $this->subscription(['period_end' => Carbon::today()->subDays(10)]);
            $held = $this->subscription([
                'period_end' => Carbon::today()->subDays(40),
                'status' => SubscriptionStatus::Hold,
            ]);

            foreach ([0, 1, 2] as $days) {
                $this->artisan('eswachh:send-renewal-reminders --date='.Carbon::today()->addDays($days)->toDateString())
                    ->assertSuccessful();
            }

            // Both would be daily on the defaults; both are slowed by the boxes.
            $this->assertSame(1, Message::query()->where('subscription_id', $overdue->id)->count());
            $this->assertSame(1, Message::query()->where('subscription_id', $held->id)->count());
        });
    }

    public function test_an_overdue_reminder_says_the_plan_has_expired(): void
    {
        SectorContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()->subDays(3)]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            $message = Message::query()->firstOrFail();
            $this->assertSame(MessagePurpose::RenewalOverdue, $message->purpose);
            $this->assertStringContainsString('expired', $message->body);
        });
    }

    public function test_a_plan_on_hold_is_chased_even_with_no_held_at(): void
    {
        SectorContext::withoutScope(function () {
            /*
             * `held_at` is null on every plan that came across from v1 - the
             * importer never set it - so any rule that counts days from it
             * reaches none of them. Thirteen plans were on hold here and an
             * earlier attempt at this matched exactly zero.
             *
             * The status is enough. A plan on hold is not being cleaned and is
             * not paid for, whenever that started.
             */
            $this->subscription([
                'period_end' => Carbon::today()->subDays(40),
                'held_at' => null,
                'status' => SubscriptionStatus::Hold,
            ]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            $message = Message::query()->firstOrFail();
            $this->assertSame(MessagePurpose::PutOnHold, $message->purpose);
            $this->assertStringContainsString('on hold', $message->body);
        });
    }

    public function test_running_the_reminder_job_twice_messages_once(): void
    {
        SectorContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()->subDays(3)]);

            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();
            $this->artisan('eswachh:send-renewal-reminders')->assertSuccessful();

            $this->assertSame(1, Message::query()->count());
        });
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        SectorContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()]);

            $this->artisan('eswachh:send-renewal-reminders --dry-run')->assertSuccessful();

            $this->assertSame(0, Message::query()->count());
        });
    }

    public function test_holding_respects_the_grace_period(): void
    {
        SectorContext::withoutScope(function () {
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
        SectorContext::withoutScope(function () {
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
        SectorContext::withoutScope(function () {
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
        SectorContext::withoutScope(function () {
            $this->subscription(['period_end' => Carbon::today()->subDays(20)]);

            $this->artisan('eswachh:hold-overdue --grace=7')->assertSuccessful();

            $message = Message::query()->firstOrFail();
            $this->assertSame(MessagePurpose::PutOnHold, $message->purpose);
            $this->assertStringContainsString('paused', $message->body);
        });
    }

    public function test_the_hold_dry_run_changes_nothing(): void
    {
        SectorContext::withoutScope(function () {
            $subscription = $this->subscription(['period_end' => Carbon::today()->subDays(20)]);

            $this->artisan('eswachh:hold-overdue --grace=7 --dry-run')->assertSuccessful();

            $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
            $this->assertSame(0, Message::query()->count());
        });
    }

    public function test_the_jobs_cover_every_branch(): void
    {
        $other = Branch::factory()->create();

        SectorContext::withoutScope(function () use ($other) {
            $this->subscription(['period_end' => Carbon::today()->subDays(3)]);
            $this->subscription(['period_end' => Carbon::today()->subDays(3)], $other);

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
