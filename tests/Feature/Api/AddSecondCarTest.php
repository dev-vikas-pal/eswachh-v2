<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A household with two cars buying a second plan.
 *
 * v1 allowed this. v2 sent them to the public signup form, which cannot serve
 * them at all: that form proves a mobile number with a code and then refuses
 * numbers it already knows, so an existing customer was told their own number
 * was taken. These tests pin down the path that actually works.
 */
class AddSecondCarTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $account;

    private Customer $customer;

    private VehicleModel $model;

    private Package $package;

    private ServiceType $serviceType;

    private Duration $duration;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
        $this->account = User::factory()->customer($this->branch)->create(['phone' => '9876543210']);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->account->id,
            'sector_id' => Sector::factory()->create(['branch_id' => $this->branch->id])->id,
        ]);

        // Their first car, already on the books.
        Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'registration' => 'UP16AB1111',
        ]);

        $category = VehicleCategory::create(['name' => 'Hatchback', 'price_paise' => 30000, 'status' => true]);
        $this->model = VehicleModel::create([
            'vehicle_category_id' => $category->id, 'name' => 'Swift', 'status' => true,
        ]);

        $this->package = Package::create(['name' => 'Basic', 'price_paise' => 20000, 'status' => true]);
        $this->serviceType = ServiceType::create(['name' => 'Exterior', 'price_paise' => 10000, 'status' => true]);
        $this->duration = Duration::create([
            'name' => '1 Month', 'months' => 1, 'discount_paise' => 0, 'status' => true,
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_customer_can_add_a_second_car(): void
    {
        $this->actingAs($this->account)
            ->postJson('/api/v1/portal/plans', $this->car())
            ->assertCreated()
            ->assertJsonPath('quote.total', 600);

        $plan = SectorContext::withoutScope(
            fn () => Subscription::query()->whereHas('vehicle', fn ($v) => $v->where('registration', 'UP16AB2222'))->first()
        );

        $this->assertNotNull($plan);
        $this->assertSame($this->customer->id, $plan->customer_id);
        // Pending and unpaid: only the verified callback moves either.
        $this->assertSame(SubscriptionStatus::Pending, $plan->status);
        $this->assertSame(0, (int) $plan->paid_amount_paise);

        // A payment is opened before the window appears, so an abandoned
        // checkout still leaves a record to chase.
        $this->assertTrue(
            SectorContext::withoutScope(fn () => Payment::query()
                ->where('subscription_id', $plan->id)
                ->where('status', PaymentStatus::Initiated)
                ->exists())
        );
    }

    public function test_the_new_plan_lands_on_their_own_branch_without_being_told(): void
    {
        $this->actingAs($this->account)->postJson('/api/v1/portal/plans', $this->car())->assertCreated();

        $plan = SectorContext::withoutScope(fn () => Subscription::query()->latest('created_at')->first());

        // Nothing in the request says who they are or where they live: it all
        // comes from the session, so there is nothing to point elsewhere.
        $this->assertSame($this->branch->id, $plan->branch_id);
    }

    public function test_a_car_registered_to_somebody_else_is_refused(): void
    {
        $neighbour = Customer::factory()->create(['branch_id' => $this->branch->id]);

        Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $neighbour->id,
            'registration' => 'UP16AB2222',
        ]);

        $this->actingAs($this->account)
            ->postJson('/api/v1/portal/plans', $this->car())
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');
    }

    public function test_a_car_that_already_has_a_running_plan_is_sent_to_renew(): void
    {
        $vehicle = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'registration' => 'UP16AB2222',
        ]);

        Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => SubscriptionStatus::Active,
            'period_end' => now()->addMonth(),
        ]);

        // Two live plans on one car would be billed twice and cleaned once.
        $this->actingAs($this->account)
            ->postJson('/api/v1/portal/plans', $this->car())
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');
    }

    public function test_the_price_is_not_taken_from_the_request(): void
    {
        $this->actingAs($this->account)
            ->postJson('/api/v1/portal/plans', $this->car(['amount_paise' => 100]))
            ->assertCreated();

        $plan = SectorContext::withoutScope(fn () => Subscription::query()->latest('created_at')->first());

        $this->assertSame(60000, (int) $plan->amount_paise);
    }

    public function test_staff_have_no_business_here(): void
    {
        // The office adds a car from the plan screen, which records who did it.
        $owner = User::factory()->franchiseOwner($this->branch)->create();

        $this->actingAs($owner)
            ->postJson('/api/v1/portal/plans', $this->car())
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function car(array $extra = []): array
    {
        return array_merge([
            'registration' => 'up 16 ab 2222',
            'vehicle_model_id' => $this->model->id,
            'package_id' => $this->package->id,
            'service_type_id' => $this->serviceType->id,
            'duration_id' => $this->duration->id,
        ], $extra);
    }
}
