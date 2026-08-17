<?php

namespace Tests\Feature\Api;

use App\Domain\Auth\PhoneCodes;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\ClothBundle;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\LoginCode;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Signing up from the public site.
 *
 * The only unauthenticated write in the system, so these tests are mostly about
 * what it refuses: an unproved number, a car somebody already has, a price sent
 * from the browser.
 */
class PublicSignupTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Sector $sector;

    private VehicleModel $model;

    private Package $package;

    private ServiceType $serviceType;

    private Duration $duration;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
        $this->sector = Sector::factory()->create(['branch_id' => $this->branch->id]);

        // Somebody has to cover it, or the signup is correctly refused: an
        // address nobody services takes money for a round that never happens.
        User::factory()->franchiseOwner($this->branch)->create()
            ->sectors()->syncWithoutDetaching([$this->sector->id]);

        $category = VehicleCategory::create(['name' => 'Hatchback', 'price_paise' => 30000, 'status' => true]);
        $this->model = VehicleModel::create([
            'vehicle_category_id' => $category->id,
            'name' => 'Swift',
            'status' => true,
        ]);

        $this->package = Package::create(['name' => 'Basic', 'price_paise' => 20000, 'status' => true]);
        $this->serviceType = ServiceType::create(['name' => 'Exterior', 'price_paise' => 10000, 'status' => true]);
        $this->duration = Duration::create([
            'name' => '1 Month',
            'months' => 1,
            'discount_paise' => 0,
            'status' => true,
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_visitor_can_sign_up_and_a_payment_is_opened(): void
    {
        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order())
            ->assertCreated()
            ->assertJsonPath('quote.total', 600);

        $customer = Customer::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('9876543210', $customer->phone);

        // The territory comes from the address they gave, and it is the only
        // thing that decides who services them. It used to be checked as a
        // branch_id copied onto the customer, which nothing reads any more.
        $this->assertSame($this->sector->id, $customer->sector_id, 'The address decides the territory.');

        $plan = Subscription::withoutGlobalScopes()->firstOrFail();

        // Pending, and unpaid. Only the verified callback moves either of these.
        $this->assertSame(SubscriptionStatus::Pending, $plan->status);
        $this->assertSame(0, (int) $plan->paid_amount_paise);
        $this->assertSame(60000, (int) $plan->amount_paise);

        // Opened before the payment window, so an abandoned signup still leaves
        // a record to chase.
        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(PaymentStatus::Initiated, $payment->status);
        $this->assertSame(60000, (int) $payment->amount_paise);
    }

    public function test_the_new_account_can_sign_in_with_a_code(): void
    {
        $this->provedNumber('9876543210');
        $this->postJson('/api/v1/public/signup', $this->order())->assertCreated();

        $account = User::query()->where('phone', '9876543210')->firstOrFail();
        $this->assertSame(UserRole::Customer, $account->role);

        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210'])->assertOk();

        LoginCode::query()->where('purpose', PhoneCodes::LOGIN)->latest('created_at')->first()
            ->forceFill(['code_hash' => Hash::make('123456')])->save();

        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '123456'])
            ->assertOk();

        $this->assertAuthenticatedAs($account);
    }

    public function test_nothing_is_created_without_a_proved_number(): void
    {
        $this->postJson('/api/v1/public/signup', $this->order())
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // v1's gate, and worth keeping: without it the form fills the database
        // with plans for numbers that do not exist.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_login_code_cannot_be_used_to_sign_up(): void
    {
        $existing = User::factory()->customer($this->branch)->create(['phone' => '9000000009']);

        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9000000009'])->assertOk();
        LoginCode::query()->latest('created_at')->first()->forceFill(['code_hash' => Hash::make('123456')])->save();

        // A code texted to somebody to sign in must not register their number
        // against a stranger's new account.
        $this->postJson('/api/v1/public/signup', $this->order(['phone' => '9000000009', 'code' => '123456']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertNotNull($existing->id);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_the_price_is_not_taken_from_the_request(): void
    {
        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order([
            'amount_paise' => 100,
            'total' => 1,
        ]))->assertCreated();

        // v1 posted its own total and was charged it.
        $this->assertSame(60000, (int) Subscription::withoutGlobalScopes()->firstOrFail()->amount_paise);
    }

    public function test_a_car_already_registered_is_refused(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'registration' => 'UP42BJ9003',
        ]);

        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order())
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');
    }

    public function test_a_number_already_registered_cannot_ask_for_a_signup_code(): void
    {
        User::factory()->customer($this->branch)->create(['phone' => '9876543210']);

        $this->postJson('/api/v1/public/signup/code', ['phone' => '9876543210'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('login_codes', 0);
    }

    public function test_a_code_only_signs_up_once(): void
    {
        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order())->assertCreated();

        // Replaying the same code must not make a second customer.
        $this->postJson('/api/v1/public/signup', $this->order(['registration' => 'UP42BJ9004']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_a_refused_order_does_not_burn_the_code(): void
    {
        /*
         * The code used to be spent before the car number was checked, so a
         * plate already on the books burned it too. The customer corrected the
         * plate, retyped the same code, and was told it was invalid - with
         * nothing on screen to explain why, and asking for a new one did it
         * again on the next mistake.
         */
        SectorContext::withoutScope(fn () => Vehicle::factory()->create([
            'registration' => 'UP42BJ9003',
        ]));

        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order())
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');

        // Same code, corrected plate. This is the whole point.
        $this->postJson('/api/v1/public/signup', $this->order(['registration' => 'UP42BJ9004']))
            ->assertCreated();
    }

    public function test_a_wrong_code_still_counts_against_the_attempt_limit(): void
    {
        // Checking without spending must not make guessing free.
        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order(['code' => '000000']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(
            1,
            (int) LoginCode::query()->latest('created_at')->first()->attempts
        );
    }

    public function test_a_sector_nobody_covers_is_refused(): void
    {
        $orphan = SectorContext::withoutScope(fn () => Sector::factory()->create());

        $this->provedNumber('9876543210');

        /*
         * Read from the assignment, not from a branch_id on the sector. That
         * column stopped meaning anything when territory moved to user_sector,
         * so a sector the address form had happily offered was refused here.
         */
        $this->postJson('/api/v1/public/signup', $this->order(['sector_id' => $orphan->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sector_id');
    }

    public function test_the_address_form_only_offers_sectors_somebody_covers(): void
    {
        $orphan = SectorContext::withoutScope(
            fn () => Sector::factory()->create(['area_id' => $this->sector->area_id])
        );

        $offered = $this->getJson('/api/v1/public/locations?level=sectors&parent_id='.$this->sector->area_id)
            ->assertOk()
            ->json('data.*.id');

        // Offering one and then refusing it at the payment step is the worst of
        // both: the two answers come from the same fact now.
        $this->assertContains($this->sector->id, $offered);
        $this->assertNotContains($orphan->id, $offered);
    }

    public function test_a_taken_car_number_is_refused_before_a_code_is_sent(): void
    {
        SectorContext::withoutScope(fn () => Vehicle::factory()->create([
            'registration' => 'UP42BJ9003',
        ]));

        /*
         * The customer used to hear this at the payment step, with the whole
         * form filled in and a code already typed - and nothing to do but start
         * again. Refused here, no message is spent on it either.
         */
        $this->postJson('/api/v1/public/signup/code', [
            'phone' => '9876543210',
            'registration' => 'UP42BJ9003',
        ])->assertStatus(422)->assertJsonValidationErrors('registration');

        $this->assertDatabaseCount('login_codes', 0);
    }

    public function test_an_uncovered_sector_is_refused_before_a_code_is_sent(): void
    {
        $orphan = SectorContext::withoutScope(fn () => Sector::factory()->create());

        $this->postJson('/api/v1/public/signup/code', [
            'phone' => '9876543210',
            'sector_id' => $orphan->id,
        ])->assertStatus(422)->assertJsonValidationErrors('sector_id');

        $this->assertDatabaseCount('login_codes', 0);
    }

    public function test_the_payment_is_stamped_with_the_customers_sector(): void
    {
        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order())->assertCreated();

        $payment = SectorContext::withoutScope(fn () => Payment::query()->firstOrFail());

        /*
         * It used to read the customer through a sector-scoped relation, from a
         * request with nobody signed in - so the scope returned nothing and the
         * stamp landed null. Every payment taken from the public form was then
         * invisible to the franchise that had just earned it.
         */
        $this->assertSame($this->sector->id, $payment->sector_id);
    }

    public function test_cloths_are_not_sold_while_the_service_is_off(): void
    {
        $bundle = ClothBundle::create([
            'name' => '30 cloths', 'cloth_count' => 30, 'price_paise' => 30000, 'status' => true,
        ]);

        SiteSettings::put(['cloth_service_enabled' => '0']);

        // The form is not what enforces this: a stale page would otherwise
        // still sell something the business has switched off.
        $this->assertSame([], $this->getJson('/api/v1/public/catalogue')->json('data.cloth_bundles'));

        $this->provedNumber('9876543210');

        $this->postJson('/api/v1/public/signup', $this->order(['cloth_bundle_id' => $bundle->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cloth_bundle_id');
    }

    // --------------------------------------------------------------- helpers

    /** Ask for a signup code and put a known one in its place. */
    private function provedNumber(string $phone): void
    {
        $this->postJson('/api/v1/public/signup/code', ['phone' => $phone])->assertOk();

        LoginCode::query()
            ->where('purpose', PhoneCodes::SIGNUP)
            ->latest('created_at')
            ->first()
            ->forceFill(['code_hash' => Hash::make('123456')])
            ->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Vikas Pal',
            'phone' => '9876543210',
            'code' => '123456',
            'email' => 'vikas@example.test',
            'registration' => 'UP42BJ9003',
            'vehicle_model_id' => $this->model->id,
            'package_id' => $this->package->id,
            'service_type_id' => $this->serviceType->id,
            'duration_id' => $this->duration->id,
            'sector_id' => $this->sector->id,
            'house_no' => 'A-101',
            'preferred_time' => '09:00',
        ], $overrides);
    }
}
