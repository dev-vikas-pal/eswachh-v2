<?php

namespace Tests\Feature\Api;

use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Sector;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuthAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ourBranch;

    private Branch $theirBranch;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->ourBranch = Branch::factory()->create(['name' => 'Franchise A']);
        $this->theirBranch = Branch::factory()->create(['name' => 'Franchise B']);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ auth

    public function test_a_user_can_sign_in(): void
    {
        User::factory()->franchiseOwner($this->ourBranch)->create([
            'email' => 'owner@eswachh.test',
        ]);

        $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'owner@eswachh.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.role.value', 'franchise_owner');

        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_does_not_reveal_whether_the_address_exists(): void
    {
        User::factory()->create(['email' => 'real@eswachh.test']);

        $known = $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'real@eswachh.test', 'password' => 'wrong',
        ])->assertStatus(422);

        $unknown = $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'nobody@eswachh.test', 'password' => 'wrong',
        ])->assertStatus(422);

        // The same answer either way.
        $this->assertSame(
            $known->json('errors.email'),
            $unknown->json('errors.email')
        );
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        User::factory()->create(['email' => 'blocked@eswachh.test', 'status' => false]);

        $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'blocked@eswachh.test', 'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        User::factory()->create(['email' => 'target@eswachh.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromSpa()->postJson('/api/v1/login', [
                'email' => 'target@eswachh.test', 'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'target@eswachh.test', 'password' => 'wrong',
        ])->assertStatus(429);
    }

    // -------------------------------------------------------------------- me

    public function test_me_returns_abilities_and_only_the_users_own_sectors(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/me')->assertOk();

        $this->assertContains('view.subscription', $response->json('data.abilities'));
        $this->assertFalse($response->json('data.sees_all_sectors'));

        // The filter must offer their own territory and nothing else.
        $this->assertSame(
            [$this->sectorOf($this->ourBranch)],
            array_column($response->json('sectors'), 'id')
        );
    }

    public function test_me_offers_every_sector_to_a_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $ids = $this->actingAs($admin)->getJson('/api/v1/me')->assertOk()->json('sectors.*.id');

        // An administrator holds no assignments at all - covering everything is
        // a role, not a stack of pivot rows somebody has to maintain.
        $this->assertEqualsCanonicalizing(
            [$this->sectorOf($this->ourBranch), $this->sectorOf($this->theirBranch)],
            $ids
        );
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    // ------------------------------------------------------------- dashboard

    public function test_the_dashboard_counts_only_the_callers_branch(): void
    {
        $this->makeSubscription($this->ourBranch, 'OURS-01', SubscriptionStatus::Active);
        $this->makeSubscription($this->ourBranch, 'OURS-02', SubscriptionStatus::Hold);
        $this->makeSubscription($this->theirBranch, 'THEIRS-01', SubscriptionStatus::Active);

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.subscriptions.active', 1);
        $response->assertJsonPath('data.subscriptions.hold', 1);
    }

    public function test_the_dashboard_counts_everything_for_a_super_admin(): void
    {
        $this->makeSubscription($this->ourBranch, 'OURS-03', SubscriptionStatus::Active);
        $this->makeSubscription($this->theirBranch, 'THEIRS-02', SubscriptionStatus::Active);

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.subscriptions.active', 2);
    }

    public function test_a_franchise_owner_cannot_ask_for_another_branch(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)
            ->getJson('/api/v1/dashboard?sector_id='.$this->sectorOf($this->theirBranch))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_narrow_to_one_branch(): void
    {
        $this->makeSubscription($this->ourBranch, 'OURS-04', SubscriptionStatus::Active);
        $this->makeSubscription($this->theirBranch, 'THEIRS-03', SubscriptionStatus::Active);

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard?sector_id='.$this->sectorOf($this->theirBranch))
            ->assertOk()
            ->assertJsonPath('data.subscriptions.active', 1);
    }

    public function test_each_dashboard_count_can_be_opened_as_a_list(): void
    {
        $this->makeSubscription($this->ourBranch, 'IN-DATE', SubscriptionStatus::Active, Carbon::today()->addMonth());
        $this->makeSubscription($this->ourBranch, 'OVERDUE', SubscriptionStatus::Active, Carbon::today()->subDays(5));
        $this->makeSubscription($this->ourBranch, 'PAUSED', SubscriptionStatus::Hold);

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $this->actingAs($owner);

        $counts = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.subscriptions');

        /*
         * A tile that opens a different number from the one it displays is
         * worse than a tile that does not open at all - so each filter the
         * dashboard links to has to return exactly what the dashboard counted.
         */
        $lists = [
            'active' => '/api/v1/subscriptions?filter[status]=active',
            'current' => '/api/v1/subscriptions?filter[current]=1',
            'expired' => '/api/v1/subscriptions?filter[expired]=1',
            'hold' => '/api/v1/subscriptions?filter[status]=hold',
        ];

        foreach ($lists as $key => $url) {
            $this->assertSame(
                $counts[$key],
                $this->getJson($url)->assertOk()->json('meta.total'),
                "The {$key} tile and the list it opens disagree.",
            );
        }
    }

    public function test_a_finished_period_is_hidden_unless_it_is_asked_for(): void
    {
        $plan = $this->makeSubscription($this->ourBranch, 'DONE-01', SubscriptionStatus::Active);
        $plan->forceFill(['status' => SubscriptionStatus::Ended])->save();

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        // A plan is a chain of periods; listing every one of them showed the
        // same car several times over and read as duplicate records.
        $this->actingAs($owner)->getJson('/api/v1/subscriptions')
            ->assertOk()->assertJsonPath('meta.total', 0);

        // But "show me what has ended" is a real question and must not come
        // back empty.
        $this->actingAs($owner)->getJson('/api/v1/subscriptions?filter[status]=ended')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_expired_is_counted_separately_but_is_still_active(): void
    {
        // The v1 rule, carried over deliberately: an overdue subscription keeps
        // running until somebody acts on it.
        $this->makeSubscription($this->ourBranch, 'OVERDUE-01', SubscriptionStatus::Active, Carbon::today()->subDays(10));
        $this->makeSubscription($this->ourBranch, 'CURRENT-01', SubscriptionStatus::Active, Carbon::today()->addMonth());

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.subscriptions.active', 2);
        $response->assertJsonPath('data.subscriptions.expired', 1);
        $response->assertJsonPath('data.subscriptions.current', 1);
    }

    // --------------------------------------------------------- subscriptions

    public function test_the_subscription_list_is_scoped(): void
    {
        $this->makeSubscription($this->ourBranch, 'LIST-MINE', SubscriptionStatus::Active);
        $this->makeSubscription($this->theirBranch, 'LIST-THEIRS', SubscriptionStatus::Active);

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $registrations = $this->actingAs($owner)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->json('data.*.vehicle.registration');

        $this->assertSame(['LIST-MINE'], $registrations);
    }

    public function test_reading_another_branches_subscription_is_not_found(): void
    {
        $theirs = $this->makeSubscription($this->theirBranch, 'HIDDEN-01', SubscriptionStatus::Active);

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        // 404, not 403: a 403 would confirm the record exists.
        $this->actingAs($owner)
            ->getJson('/api/v1/subscriptions/'.$theirs->id)
            ->assertNotFound();
    }

    public function test_a_cleaner_cannot_list_subscriptions(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($cleaner)->getJson('/api/v1/subscriptions')->assertForbidden();
    }

    /** The sector every factory-made branch is given. */
    private function sectorOf(Branch $branch): ?string
    {
        return SectorContext::withoutScope(
            fn () => Sector::query()->where('branch_id', $branch->id)->value('id')
        );
    }

    private function makeSubscription(
        Branch $branch,
        string $registration,
        SubscriptionStatus $status,
        ?Carbon $periodEnd = null
    ): Subscription {
        return SectorContext::withoutScope(function () use ($branch, $registration, $status, $periodEnd) {
            // The sector every factory-made branch gets, so the owner created
            // alongside it covers these customers.
            $sectorId = $this->sectorOf($branch);

            $customer = Customer::create([
                'branch_id' => $branch->id,
                'sector_id' => $sectorId,
                'name' => 'Customer '.$registration,
                'phone' => (string) random_int(6000000000, 9999999999),
            ]);

            $vehicle = Vehicle::create([
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'registration' => $registration,
            ]);

            return Subscription::create([
                'branch_id' => $branch->id,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'sequence' => 1,
                'period_start' => Carbon::today()->subMonth(),
                'period_end' => $periodEnd ?? Carbon::today()->addMonth(),
                'status' => $status,
                'amount_paise' => 99900,
                'paid_amount_paise' => 99900,
            ]);
        });
    }
}
