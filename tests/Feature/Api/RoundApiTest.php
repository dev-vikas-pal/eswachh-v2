<?php

namespace Tests\Feature\Api;

use App\Enums\ServiceOutcome;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\ServiceLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ourBranch;

    private Branch $theirBranch;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->ourBranch = Branch::factory()->create();
        $this->theirBranch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_cleaner_gets_their_own_round_without_asking(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $other = User::factory()->cleaner($this->ourBranch)->create();

        SectorContext::withoutScope(function () use ($cleaner, $other) {
            $this->carFor($cleaner);
            $this->carFor($cleaner);
            $this->carFor($other);
        });

        $response = $this->actingAs($cleaner)->getJson('/api/v1/round')->assertOk();

        $this->assertCount(2, $response->json('data.stops'));
        $this->assertSame(2, $response->json('data.summary.due'));
    }

    public function test_a_cleaner_cannot_ask_for_somebody_elses_round(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $other = User::factory()->cleaner($this->ourBranch)->create();

        SectorContext::withoutScope(function () use ($cleaner, $other) {
            $this->carFor($cleaner);
            $this->carFor($other);
            $this->carFor($other);
            $this->carFor($other);
        });

        // The cleaner_id is ignored rather than refused, because a cleaner's
        // round is always their own by definition.
        $response = $this->actingAs($cleaner)
            ->getJson("/api/v1/round?cleaner_id={$other->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data.stops'));
    }

    public function test_an_owner_must_say_whose_round_they_want(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->getJson('/api/v1/round')->assertStatus(422);
    }

    public function test_an_owner_cannot_look_at_another_branchs_cleaner(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $outsider = User::factory()->cleaner($this->theirBranch)->create();

        $this->actingAs($owner)
            ->getJson("/api/v1/round?cleaner_id={$outsider->id}")
            ->assertNotFound();
    }

    public function test_recording_a_stop_twice_corrects_it(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $vehicle = SectorContext::withoutScope(fn () => $this->carFor($cleaner));

        $this->actingAs($cleaner)->postJson("/api/v1/round/vehicles/{$vehicle->id}", [
            'outcome' => ServiceOutcome::Missed->value,
        ])->assertCreated();

        $this->actingAs($cleaner)->postJson("/api/v1/round/vehicles/{$vehicle->id}", [
            'outcome' => ServiceOutcome::Cleaned->value,
            'note' => 'Went back after lunch.',
        ])->assertCreated()->assertJsonPath('data.outcome.value', 'cleaned');

        // A slow connection and a second tap must not inflate the day.
        $this->assertSame(1, SectorContext::withoutScope(fn () => ServiceLog::query()->count()));
    }

    public function test_a_stop_cannot_be_recorded_on_another_branchs_car(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $theirCleaner = User::factory()->cleaner($this->theirBranch)->create();
        $theirCar = SectorContext::withoutScope(fn () => $this->carFor($theirCleaner));

        $this->actingAs($cleaner)
            ->postJson("/api/v1/round/vehicles/{$theirCar->id}", [
                'outcome' => ServiceOutcome::Cleaned->value,
            ])
            ->assertNotFound();
    }

    public function test_a_customer_cannot_record_service(): void
    {
        $customer = User::factory()->customer($this->ourBranch)->create();
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $vehicle = SectorContext::withoutScope(fn () => $this->carFor($cleaner));

        /*
         * 404 rather than 403, and that is a tightening rather than a change of
         * mind.
         *
         * Under branch scoping a customer could see every car in their branch,
         * so the ability check was the only thing stopping them and the answer
         * was "you may not". Under sector scoping somebody else's car is not
         * theirs to see at all, so the honest answer is "there is no such car" -
         * which is the rule the rest of this codebase already follows, because
         * refusing must not confirm that the record exists.
         */
        $this->actingAs($customer)
            ->postJson("/api/v1/round/vehicles/{$vehicle->id}", [
                'outcome' => ServiceOutcome::Cleaned->value,
            ])
            ->assertNotFound();
    }

    public function test_a_cleaner_can_only_mark_their_own_attendance(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $other = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($cleaner)->postJson('/api/v1/attendance', [
            'cleaner_id' => $other->id,
            'status' => 'absent',
        ])->assertForbidden();

        // Their own is fine, and needs no id.
        $this->actingAs($cleaner)->postJson('/api/v1/attendance', [
            'status' => 'present',
        ])->assertCreated()->assertJsonPath('data.cleaner.id', $cleaner->id);
    }

    public function test_attendance_cannot_be_marked_for_a_future_day(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($cleaner)->postJson('/api/v1/attendance', [
            'status' => 'present',
            'date' => now()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_coverage_shows_who_has_not_been_marked_at_all(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $marked = User::factory()->cleaner($this->ourBranch)->create();
        User::factory()->cleaner($this->ourBranch)->count(2)->create();

        $this->actingAs($marked)->postJson('/api/v1/attendance', ['status' => 'present']);

        $response = $this->actingAs($owner)->getJson('/api/v1/attendance/coverage')->assertOk();

        // Nobody has said whether the other two worked today. On a dashboard
        // that is a question, not a blank.
        $this->assertSame(2, $response->json('data.totals.unmarked_cleaners'));
        $this->assertCount(3, $response->json('data.cleaners'));
    }

    public function test_coverage_counts_work_from_logs_not_from_a_typed_number(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $cars = SectorContext::withoutScope(
            fn () => collect(range(1, 3))->map(fn () => $this->carFor($cleaner))
        );

        $this->actingAs($cleaner)->postJson("/api/v1/round/vehicles/{$cars[0]->id}", [
            'outcome' => ServiceOutcome::Cleaned->value,
        ]);
        $this->actingAs($cleaner)->postJson("/api/v1/round/vehicles/{$cars[1]->id}", [
            'outcome' => ServiceOutcome::CarAbsent->value,
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/attendance/coverage')->assertOk();

        $this->assertSame(3, $response->json('data.totals.due'));
        $this->assertSame(1, $response->json('data.totals.cleaned'));
        // One car nobody said anything about at all.
        $this->assertSame(1, $response->json('data.totals.unaccounted'));
    }

    public function test_coverage_does_not_leak_another_branchs_cleaners(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        User::factory()->cleaner($this->ourBranch)->create();
        User::factory()->cleaner($this->theirBranch)->count(4)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/attendance/coverage')->assertOk();

        $this->assertCount(1, $response->json('data.cleaners'));
    }

    private function carFor(User $cleaner): Vehicle
    {
        $customer = Customer::factory()->create(['branch_id' => $cleaner->branch_id]);

        $vehicle = Vehicle::factory()->forCustomer($customer)->create([
            'assigned_cleaner_id' => $cleaner->id,
        ]);

        Subscription::factory()->forVehicle($vehicle)->create([
            'duration_id' => Duration::factory()->create()->id,
        ]);

        return $vehicle;
    }
}
