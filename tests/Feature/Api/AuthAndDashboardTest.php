<?php

namespace Tests\Feature\Api;

use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
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
        BranchContext::reset();

        $this->ourBranch = Branch::factory()->create(['name' => 'Franchise A']);
        $this->theirBranch = Branch::factory()->create(['name' => 'Franchise B']);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
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

    public function test_me_returns_abilities_and_only_the_users_own_branch(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/me')->assertOk();

        $response->assertJsonPath('data.branch.name', 'Franchise A');
        $this->assertContains('view.subscription', $response->json('data.abilities'));
        $this->assertFalse($response->json('data.sees_all_branches'));

        // The selector must offer their branch and nothing else.
        $this->assertSame(['Franchise A'], array_column($response->json('branches'), 'name'));
    }

    public function test_me_offers_every_branch_to_a_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $names = $this->actingAs($admin)->getJson('/api/v1/me')->assertOk()->json('branches.*.name');

        $this->assertEqualsCanonicalizing(['Franchise A', 'Franchise B'], $names);
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
            ->getJson('/api/v1/dashboard?branch_id='.$this->theirBranch->id)
            ->assertForbidden();
    }

    public function test_a_super_admin_can_narrow_to_one_branch(): void
    {
        $this->makeSubscription($this->ourBranch, 'OURS-04', SubscriptionStatus::Active);
        $this->makeSubscription($this->theirBranch, 'THEIRS-03', SubscriptionStatus::Active);

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard?branch_id='.$this->theirBranch->id)
            ->assertOk()
            ->assertJsonPath('data.subscriptions.active', 1);
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

    private function makeSubscription(
        Branch $branch,
        string $registration,
        SubscriptionStatus $status,
        ?Carbon $periodEnd = null
    ): Subscription {
        return BranchContext::forBranch($branch->id, function () use ($branch, $registration, $status, $periodEnd) {
            $customer = Customer::create([
                'branch_id' => $branch->id,
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
