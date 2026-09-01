<?php

namespace Tests\Feature\Api;

use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\ClothBundle;
use App\Models\ClothMovement;
use App\Models\Customer;
use App\Models\Society;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Collecting cloths, returning them, and topping them up.
 *
 * The three cloth screens the requirements document asks for. v1 had all three;
 * v2 had the endpoints for two of them and no screens at all.
 */
class ClothFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cleaner;

    private Vehicle $vehicle;

    private Subscription $plan;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        /*
         * The service ships switched off, and its endpoints are gated on that.
         * A test of the cloth round has to turn on the thing it is testing -
         * the switch itself is covered by FeatureSwitchTest.
         */
        \App\Support\Settings\SiteSettings::put(['cloth_service_enabled' => '1']);

        $this->branch = Branch::factory()->create();
        $this->cleaner = User::factory()->cleaner($this->branch)->create();

        $society = Society::create([
            'sector_id' => \App\Models\Sector::factory()->create(['branch_id' => $this->branch->id])->id,
            'name' => 'Amrapali Castle',
            'surcharge_paise' => 0,
            'status' => true,
        ]);

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'society_id' => $society->id,
            'house_no' => 'A-101',
        ]);

        $this->vehicle = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'registration' => 'UP16AB1234',
            'assigned_cleaner_id' => $this->cleaner->id,
        ]);

        $this->plan = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => SubscriptionStatus::Active,
            'cloth_service' => true,
            'cloth_balance' => 20,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------- collecting

    public function test_a_cleaner_finds_a_car_by_its_number(): void
    {
        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/cloth/lookup', ['registration' => 'up 16 ab 1234'])
            ->assertOk()
            // Typed three ways on three days, still one car.
            ->assertJsonPath('data.registration', 'UP16AB1234')
            ->assertJsonPath('data.balance', 20)
            ->assertJsonPath('data.society', 'Amrapali Castle');
    }

    public function test_a_car_with_no_cloth_service_is_refused(): void
    {
        $this->plan->forceFill(['cloth_service' => false])->save();

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/cloth/lookup', ['registration' => 'UP16AB1234'])
            ->assertStatus(422);
    }

    public function test_what_was_already_collected_today_is_shown(): void
    {
        $this->pickup(4);

        // So a second tap reads as a correction rather than leaving somebody
        // wondering whether the first one saved.
        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/cloth/lookup', ['registration' => 'UP16AB1234'])
            ->assertOk()
            ->assertJsonPath('data.collected_today', 4);
    }

    // -------------------------------------------------------------- returning

    public function test_the_delivery_list_is_what_is_still_out(): void
    {
        $this->pickup(6);

        $body = $this->actingAs($this->cleaner)
            ->getJson('/api/v1/cloth/outstanding')
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['meta']['cars']);
        $this->assertSame(6, $body['meta']['cloths']);
        $this->assertSame('Amrapali Castle', $body['data'][0]['society']);
        $this->assertSame('UP16AB1234', $body['data'][0]['cars'][0]['registration']);
    }

    public function test_a_returned_bag_leaves_the_list(): void
    {
        $this->pickup(6);

        $this->actingAs($this->cleaner)
            ->postJson('/api/v1/round/vehicles/'.$this->vehicle->id.'/cloth', [
                'direction' => ClothMovement::DELIVERY,
                'cloth_count' => 6,
            ])
            ->assertCreated();

        $this->actingAs($this->cleaner)
            ->getJson('/api/v1/cloth/outstanding')
            ->assertOk()
            ->assertJsonPath('meta.cars', 0);
    }

    public function test_a_bag_taken_after_the_last_delivery_is_still_out(): void
    {
        // Returned on Monday, taken again on Tuesday. The Tuesday bag is out.
        $this->pickup(4, Carbon::today()->subDays(3));

        ClothMovement::create([
            'branch_id' => $this->branch->id,
            'vehicle_id' => $this->vehicle->id,
            'subscription_id' => $this->plan->id,
            'cleaner_id' => $this->cleaner->id,
            'direction' => ClothMovement::DELIVERY,
            'cloth_count' => 4,
            'moved_on' => Carbon::today()->subDays(2),
        ]);

        $this->pickup(5, Carbon::today());

        $this->actingAs($this->cleaner)
            ->getJson('/api/v1/cloth/outstanding')
            ->assertOk()
            ->assertJsonPath('meta.cars', 1)
            ->assertJsonPath('meta.cloths', 5);
    }

    public function test_a_franchise_owner_can_work_the_cloth_round(): void
    {
        // They cover for a cleaner who did not turn up, and they already hold
        // every other part of the round.
        $owner = User::factory()->franchiseOwner($this->branch)->create();

        $this->actingAs($owner)
            ->postJson('/api/v1/cloth/lookup', ['registration' => 'UP16AB1234'])
            ->assertOk();
    }

    public function test_a_customer_cannot_open_the_cloth_round(): void
    {
        $account = User::factory()->customer($this->branch)->create();

        $this->actingAs($account)
            ->getJson('/api/v1/cloth/outstanding')
            ->assertForbidden();
    }

    // --------------------------------------------------------------- topping up

    public function test_a_visitor_can_look_their_car_up_and_see_what_is_on_sale(): void
    {
        ClothBundle::create(['name' => 'Twenty', 'cloth_count' => 20, 'price_paise' => 50000, 'status' => true]);

        $this->postJson('/api/v1/public/cloth/lookup', ['registration' => 'UP16AB1234'])
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('data.balance', 20)
            ->assertJsonCount(1, 'data.bundles');
    }

    public function test_an_unknown_car_is_offered_the_signup_page(): void
    {
        // The document asks for this: a dead end here loses a customer.
        $this->postJson('/api/v1/public/cloth/lookup', ['registration' => 'XX99ZZ0000'])
            ->assertNotFound()
            ->assertJsonPath('subscribe_instead', true);
    }

    public function test_the_car_number_must_match_the_plan_being_paid_for(): void
    {
        $bundle = ClothBundle::create([
            'name' => 'Twenty', 'cloth_count' => 20, 'price_paise' => 50000, 'status' => true,
        ]);

        // An id alone must not be enough: ids leak far more easily than the
        // pairing of an id and a plate.
        $this->postJson('/api/v1/public/cloth/pay', [
            'subscription_id' => $this->plan->id,
            'registration' => 'XX99ZZ0000',
            'cloth_bundle_id' => $bundle->id,
        ])->assertNotFound();
    }

    // --------------------------------------------------------------- helpers

    private function pickup(int $count, ?Carbon $on = null): ClothMovement
    {
        return ClothMovement::create([
            'branch_id' => $this->branch->id,
            'vehicle_id' => $this->vehicle->id,
            'subscription_id' => $this->plan->id,
            'cleaner_id' => $this->cleaner->id,
            'direction' => ClothMovement::PICKUP,
            'cloth_count' => $count,
            'moved_on' => $on ?? Carbon::today(),
        ]);
    }
}
