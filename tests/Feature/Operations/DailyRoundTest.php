<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\DailyRound;
use App\Enums\AttendanceStatus;
use App\Enums\ServiceOutcome;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\ServiceLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The round replaces v1's two typed-in numbers with counted events.
 */
class DailyRoundTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cleaner;

    private DailyRound $round;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
        $this->cleaner = User::factory()->cleaner($this->branch)->create();
        $this->round = app(DailyRound::class);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_the_round_is_the_cars_with_a_live_subscription(): void
    {
        SectorContext::withoutScope(function () {
            $this->carFor($this->cleaner);
            $this->carFor($this->cleaner);
            // On hold: not work, and putting it on the list means it gets
            // marked missed every single day.
            $this->carFor($this->cleaner, ['status' => SubscriptionStatus::Hold]);
            // Ended last month.
            $this->carFor($this->cleaner, ['status' => SubscriptionStatus::Ended]);

            $this->assertCount(2, $this->round->due($this->cleaner));
        });
    }

    public function test_an_expired_period_still_counts_as_work_until_it_is_held(): void
    {
        SectorContext::withoutScope(function () {
            $this->carFor($this->cleaner, [
                'status' => SubscriptionStatus::Active,
                'period_end' => Carbon::today()->subDays(3),
            ]);

            // Overdue is not the same as stopped. Dropping an overdue car from
            // the round the day it lapses is how customers in their grace
            // period stop being served.
            $this->assertCount(0, $this->round->due($this->cleaner));
        });
    }

    public function test_another_cleaners_cars_are_not_on_this_round(): void
    {
        SectorContext::withoutScope(function () {
            $other = User::factory()->cleaner($this->branch)->create();

            $this->carFor($this->cleaner);
            $this->carFor($other);
            $this->carFor($other);

            $this->assertCount(1, $this->round->due($this->cleaner));
            $this->assertCount(2, $this->round->due($other));
        });
    }

    public function test_marking_the_same_car_twice_corrects_rather_than_counts_twice(): void
    {
        SectorContext::withoutScope(function () {
            $vehicle = $this->carFor($this->cleaner);

            $this->round->record($vehicle, $this->cleaner, ServiceOutcome::Missed);
            $this->round->record($vehicle, $this->cleaner, ServiceOutcome::Cleaned, note: 'Came back later.');

            // A double tap on a phone with a slow connection must not inflate
            // the day's figures.
            $this->assertSame(1, ServiceLog::query()->count());

            $log = ServiceLog::query()->first();
            $this->assertSame(ServiceOutcome::Cleaned, $log->outcome);
            $this->assertSame('Came back later.', $log->note);
        });
    }

    public function test_a_car_the_owner_drove_away_is_not_a_service_failure(): void
    {
        SectorContext::withoutScope(function () {
            $a = $this->carFor($this->cleaner);
            $b = $this->carFor($this->cleaner);
            $c = $this->carFor($this->cleaner);

            $this->round->record($a, $this->cleaner, ServiceOutcome::Cleaned);
            $this->round->record($b, $this->cleaner, ServiceOutcome::CarAbsent);
            $this->round->record($c, $this->cleaner, ServiceOutcome::Missed);

            $summary = $this->round->summary($this->cleaner);

            $this->assertSame(3, $summary['due']);
            $this->assertSame(1, $summary['cleaned']);
            // Only the missed car is ours to answer for.
            $this->assertSame(1, $summary['failed']);
            $this->assertSame(1, $summary['not_our_fault']);
            $this->assertSame(0, $summary['unaccounted']);
        });
    }

    public function test_a_car_nobody_said_anything_about_is_unaccounted(): void
    {
        SectorContext::withoutScope(function () {
            $a = $this->carFor($this->cleaner);
            $this->carFor($this->cleaner);
            $this->carFor($this->cleaner);

            $this->round->record($a, $this->cleaner, ServiceOutcome::Cleaned);

            // Two cars with no entry at all. Different from marked missed:
            // nobody even said what happened, and v1 could not tell them apart.
            $this->assertSame(2, $this->round->summary($this->cleaner)['unaccounted']);
        });
    }

    public function test_the_days_figures_are_counted_not_typed(): void
    {
        SectorContext::withoutScope(function () {
            $cars = collect(range(1, 5))->map(fn () => $this->carFor($this->cleaner));

            $cars->take(4)->each(
                fn (Vehicle $v) => $this->round->record($v, $this->cleaner, ServiceOutcome::Cleaned)
            );

            $summary = $this->round->summary($this->cleaner);

            // There is nowhere to write "I did 5". The number is a count of
            // rows, which is the whole reason the table exists.
            $this->assertSame(4, $summary['cleaned']);
            $this->assertSame(4, ServiceLog::query()->cleaned()->count());
        });
    }

    public function test_attendance_is_one_entry_a_day_corrected_in_place(): void
    {
        SectorContext::withoutScope(function () {
            $this->round->markAttendance($this->cleaner, AttendanceStatus::Absent);
            $this->round->markAttendance($this->cleaner, AttendanceStatus::Present, note: 'Came in late.');

            $this->assertSame(1, \App\Models\Attendance::query()->count());

            $attendance = \App\Models\Attendance::query()->first();
            $this->assertSame(AttendanceStatus::Present, $attendance->status);
            $this->assertSame('Came in late.', $attendance->note);
        });
    }

    public function test_attendance_filled_in_afterwards_says_so(): void
    {
        SectorContext::withoutScope(function () {
            $onTime = $this->round->markAttendance($this->cleaner, AttendanceStatus::Present);
            $this->assertFalse($onTime->wasMarkedLate());

            $other = User::factory()->cleaner($this->branch)->create();
            $late = $this->round->markAttendance(
                $other, AttendanceStatus::Present, Carbon::today()->subDays(4)
            );

            // A week of attendance entered on a Friday is not evidence of
            // anything, so it is visible rather than indistinguishable.
            $this->assertTrue($late->wasMarkedLate());
        });
    }

    public function test_the_round_only_covers_the_cleaners_own_branch(): void
    {
        $elsewhere = Branch::factory()->create();

        SectorContext::withoutScope(function () use ($elsewhere) {
            $this->carFor($this->cleaner);

            // Same person somehow assigned a car in another franchise. The
            // scope must hide it, not merely the query.
            $strayCustomer = Customer::factory()->create(['branch_id' => $elsewhere->id]);
            Vehicle::factory()->forCustomer($strayCustomer)->create([
                'assigned_cleaner_id' => $this->cleaner->id,
            ]);
        });

        $this->actingAs($this->cleaner);

        $this->assertCount(1, $this->round->due($this->cleaner));
    }

    // ---------------------------------------------------------------- helpers

    private function carFor(User $cleaner, array $subscription = []): Vehicle
    {
        $customer = Customer::factory()->create(['branch_id' => $cleaner->branch_id]);

        $vehicle = Vehicle::factory()->forCustomer($customer)->create([
            'assigned_cleaner_id' => $cleaner->id,
        ]);

        Subscription::factory()->forVehicle($vehicle)->create(array_merge([
            'duration_id' => Duration::factory()->create()->id,
        ], $subscription));

        return $vehicle;
    }
}
