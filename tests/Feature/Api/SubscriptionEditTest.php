<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a plan from the office.
 *
 * v1 kept the package, the cleaning type, the dates, the car and the cleaner on
 * one form, and the office expects to correct any of them in one place. What it
 * must not be able to do is type an amount: the price comes from the masters.
 */
class SubscriptionEditTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private Subscription $plan;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->franchiseOwner($this->branch)->create();

        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $vehicle = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'registration' => 'KA01AB1234',
        ]);

        $this->plan = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => SubscriptionStatus::Pending,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_the_office_can_change_the_package(): void
    {
        $package = Package::create(['name' => 'Gold', 'price_paise' => 90000, 'status' => true]);

        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['package_id' => $package->id])
            ->assertOk();

        $this->assertSame($package->id, $this->plan->fresh()->package_id);
    }

    public function test_the_car_fields_write_through_to_the_vehicle(): void
    {
        $model = VehicleModel::create([
            'vehicle_category_id' => VehicleCategory::create(['name' => 'Hatchback', 'status' => true])->id,
            'name' => 'Swift',
            'status' => true,
        ]);

        // As an administrator: the car number is theirs alone to change.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, [
                'registration' => 'ka 01 cd 5678',
                'vehicle_model_id' => $model->id,
            ])
            ->assertOk();

        $vehicle = $this->plan->fresh()->vehicle;

        // Normalised on the way in, so the same car typed three ways is one car.
        $this->assertSame('KA01CD5678', $vehicle->registration);
        $this->assertSame($model->id, $vehicle->vehicle_model_id);
    }

    public function test_a_registration_already_on_another_car_is_refused(): void
    {
        Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => Customer::factory()->create(['branch_id' => $this->branch->id])->id,
            'registration' => 'KA01ZZ9999',
        ]);

        // The clash check still applies to the one person who may renumber.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['registration' => 'KA01ZZ9999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');
    }

    public function test_a_cleaner_from_another_branch_cannot_be_given_the_car(): void
    {
        $elsewhere = User::factory()->cleaner(Branch::factory()->create())->create();

        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['assigned_cleaner_id' => $elsewhere->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_cleaner_id');
    }

    public function test_putting_a_plan_on_hold_records_when(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['status' => 'hold'])
            ->assertOk();

        $this->assertNotNull($this->plan->fresh()->held_at);

        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['status' => 'active'])
            ->assertOk();

        // Cleared on the way back, so a restarted plan does not read as paused
        // in every report that looks at held_at.
        $this->assertNull($this->plan->fresh()->held_at);
    }

    public function test_a_period_can_be_shortened_but_not_extended(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, [
                'period_end' => now()->addMonths(6)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_end');

        // Extending a period is what a payment does, not what an edit does.
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, [
                'period_end' => now()->addDays(3)->toDateString(),
            ])
            ->assertOk();
    }

    public function test_an_amount_cannot_be_typed_into_the_plan(): void
    {
        $before = $this->plan->amount_paise;

        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['amount_paise' => 100])
            ->assertOk();

        // v1 took the price from the request body and checked only that it was
        // at least one rupee.
        $this->assertSame($before, $this->plan->fresh()->amount_paise);
    }

    public function test_an_agreed_price_needs_a_reason(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['agreed_amount_paise' => 50000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('agreed_reason');
    }

    public function test_a_customer_cannot_edit_their_own_plan(): void
    {
        $account = User::factory()->customer($this->branch)->create();
        $this->customer->forceFill(['user_id' => $account->id])->save();

        // Looking at a plan and changing what it costs are different things.
        $this->actingAs($account)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['status' => 'active'])
            ->assertForbidden();
    }

    public function test_only_an_administrator_can_change_a_car_number(): void
    {
        /*
         * The plate is how a customer is found on the phone, how the cleaner
         * knows which car is theirs, and what every past payment is filed
         * under. A franchise owner's edit still saves - the plate is simply
         * left alone, and the reply says so rather than pretending otherwise.
         */
        $response = $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, [
                'registration' => 'KA09XX0001',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertSame('KA01AB1234', $this->plan->fresh()->vehicle->registration);
        $this->assertNotNull($response->json('notice'));

        // The rest of the edit went through.
        $this->assertSame('active', $this->plan->fresh()->status->value);
    }

    public function test_an_administrator_can_change_a_car_number(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['registration' => 'ka 09 xx 0001'])
            ->assertOk();

        $this->assertSame('KA09XX0001', $this->plan->fresh()->vehicle->registration);
    }

    public function test_the_business_can_lock_plan_edits_to_administrators(): void
    {
        SiteSettings::put(['lock_plan_edits_to_admin' => '1']);

        // A policy about the whole business, so it is a flag rather than an
        // edit to every franchise's role one at a time.
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['status' => 'active'])
            ->assertForbidden();

        // Never the administrator: somebody has to be able to correct a plan,
        // and locking everybody out would leave only the database.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, ['status' => 'active'])
            ->assertOk();
    }

    public function test_the_office_can_correct_the_last_payments_details(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->plan->id,
            'status' => PaymentStatus::Captured,
            'method' => 'card',
            'amount_paise' => 74900,
            'paid_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, [
                'payment' => ['method' => 'upi', 'reference' => 'pay_ABC123'],
            ])
            ->assertOk();

        $this->assertSame('upi', $payment->fresh()->method);
        $this->assertSame('pay_ABC123', $payment->fresh()->reference);
    }

    public function test_the_amount_charged_is_not_editable_from_the_plan_form(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->plan->id,
            'status' => PaymentStatus::Captured,
            'amount_paise' => 74900,
            'paid_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->patchJson('/api/v1/subscriptions/'.$this->plan->id, [
                'payment' => ['amount_paise' => 100, 'method' => 'cash'],
            ])
            ->assertOk();

        // Returning money is a refund, recorded on its own rather than by
        // rewriting what was charged.
        $this->assertSame(74900, (int) $payment->fresh()->amount_paise);
    }
}
