<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Society;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Renewing from the Renew now button in a WhatsApp message.
 *
 * Everything PublicRenewalTest guards applies here and for the same reason -
 * nobody is signed in, so the sector scope covers nothing and a careless read
 * returns null rather than failing. The difference is what proves which plan
 * this is: a token we sent to one phone, rather than a registration painted on
 * the back of the car.
 *
 * The link never expires, deliberately, so the gap between it being sent and
 * being tapped is measured in weeks. What that buys, and what has to hold, is
 * that the price is worked out when it is tapped and never when it was sent.
 */
class RenewalLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SectorContext::reset();
        config()->set('services.razorpay.enabled', false);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_token_opens_the_plan_priced_and_ready_to_pay(): void
    {
        $token = $this->plan()->renewToken();

        $response = $this->postJson('/api/v1/public/renew/link', ['token' => $token]);

        $response->assertOk();
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('data.registration', 'UP42BJ9003');

        /*
         * The same figure PublicRenewalTest pins, and it has to be: 600 car +
         * 200 package + 100 interior + 150 surcharge over three months, less
         * the 300 discount. A token that priced a renewal differently from a
         * car number would mean the same plan costing two different amounts
         * depending on how the customer arrived.
         */
        $this->assertEquals(2850, $response->json('data.amount'));

        $labels = array_column($response->json('data.lines'), 'source');
        $this->assertContains('category', $labels);
        $this->assertContains('society', $labels);
    }

    /**
     * The whole name, where the car-number lookup gives only the first.
     *
     * Not a detail: a registration is public and readable off the car, so that
     * page must not confirm who owns one. A token went to this customer's own
     * phone, so there is nobody else it could be.
     */
    public function test_the_link_shows_the_whole_name_where_a_car_number_shows_one(): void
    {
        $token = $this->plan()->renewToken();

        $this->postJson('/api/v1/public/renew/link', ['token' => $token])
            ->assertJsonPath('data.name', 'Asha Verma');

        $this->postJson('/api/v1/public/renew/lookup', ['registration' => 'UP42BJ9003'])
            ->assertJsonPath('data.name', 'Asha');
    }

    public function test_an_unknown_token_finds_nothing(): void
    {
        $this->plan()->renewToken();

        $this->postJson('/api/v1/public/renew/link', ['token' => 'nothinglikearealtoken'])
            ->assertNotFound()
            ->assertJsonPath('found', false);
    }

    /**
     * A token is made once and stays put.
     *
     * It is in a WhatsApp thread the customer can scroll back to, so a second
     * message must not silently invalidate the first.
     */
    public function test_a_token_is_stable_once_it_exists(): void
    {
        $plan = $this->plan();

        $this->assertSame($plan->renewToken(), $plan->fresh()->renewToken());
    }

    /** No plan carries a token until one is actually needed. */
    public function test_a_plan_has_no_token_until_it_is_asked_for(): void
    {
        $this->assertNull($this->plan()->renew_token);
    }

    /**
     * An ended plan is replaced, not renewed.
     *
     * The link is permanent, so one sent months ago can be tapped long after
     * the plan behind it was closed - and taking money against it would leave
     * the customer paying into something nobody is servicing.
     */
    public function test_a_link_to_an_ended_plan_is_refused(): void
    {
        $plan = $this->plan();
        $token = $plan->renewToken();

        SectorContext::withoutScope(
            fn () => $plan->forceFill(['status' => SubscriptionStatus::Ended])->save()
        );

        $this->postJson('/api/v1/public/renew/link', ['token' => $token])
            ->assertNotFound()
            ->assertJsonPath('found', false);

        $this->postJson('/api/v1/public/renew/link/pay', ['token' => $token])
            ->assertNotFound();
    }

    /** A plan on hold is exactly who this link is for. */
    public function test_a_plan_on_hold_can_be_renewed_from_its_link(): void
    {
        $plan = $this->plan();

        SectorContext::withoutScope(
            fn () => $plan->forceFill(['status' => SubscriptionStatus::Hold])->save()
        );

        $this->postJson('/api/v1/public/renew/link', ['token' => $plan->renewToken()])
            ->assertOk()
            ->assertJsonPath('found', true);
    }

    public function test_paying_from_a_link_opens_a_payment_for_the_quoted_amount(): void
    {
        $plan = $this->plan();

        $response = $this->postJson('/api/v1/public/renew/link/pay', ['token' => $plan->renewToken()]);

        $response->assertCreated();

        // Read outside the scope: payments are scoped on their stamped sector
        // and nothing is signed in here, so a plain read finds nothing.
        $payment = SectorContext::withoutScope(fn () => Payment::query()->firstOrFail());

        $this->assertSame($plan->id, $payment->subscription_id);
        $this->assertSame(285000, (int) $payment->amount_paise);
        // Opened and nothing more. Only a verified callback marks it paid.
        $this->assertSame(PaymentStatus::Initiated, $payment->status);
    }

    /**
     * The price is today's, not the one that was current when the link was sent.
     *
     * The link lives in a WhatsApp thread indefinitely, so this is the failure
     * that would actually happen: a plan repriced in the masters, a customer
     * tapping a three week old message, and the amount taken being whatever it
     * cost then.
     */
    public function test_the_price_is_worked_out_when_the_link_is_tapped(): void
    {
        $plan = $this->plan();
        $token = $plan->renewToken();

        // The plan still carries what it cost last time, as an imported one
        // would. Nothing may charge from this figure.
        SectorContext::withoutScope(
            fn () => $plan->forceFill(['amount_paise' => 22500])->save()
        );

        $this->postJson('/api/v1/public/renew/link/pay', ['token' => $token])->assertCreated();

        $this->assertSame(285000, (int) SectorContext::withoutScope(
            fn () => Payment::query()->firstOrFail()
        )->amount_paise);
    }

    private function plan(): Subscription
    {
        return SectorContext::withoutScope(function () {
            $sector = Sector::factory()->create();

            $society = Society::create([
                'sector_id' => $sector->id,
                'name' => 'Chi 5',
                'surcharge_paise' => 15000,
                'status' => true,
            ]);

            $category = VehicleCategory::create([
                'name' => 'Sedan', 'price_paise' => 60000, 'status' => true,
            ]);

            $model = VehicleModel::create([
                'vehicle_category_id' => $category->id, 'name' => 'City', 'status' => true,
            ]);

            $customer = Customer::factory()->create([
                'name' => 'Asha Verma',
                'sector_id' => $sector->id,
                'society_id' => $society->id,
            ]);

            $vehicle = Vehicle::factory()->forCustomer($customer)->create([
                'registration' => 'UP42BJ9003',
                'vehicle_model_id' => $model->id,
            ]);

            return Subscription::factory()->forVehicle($vehicle)->create([
                'status' => SubscriptionStatus::Active,
                'period_end' => Carbon::today()->addDays(20),
                'package_id' => Package::create([
                    'name' => 'Standard', 'price_paise' => 20000, 'status' => true,
                ])->id,
                'service_type_id' => ServiceType::create([
                    'name' => 'Weekly inside', 'price_paise' => 10000, 'status' => true,
                ])->id,
                'duration_id' => Duration::create([
                    'name' => '3 Months', 'months' => 3, 'discount_paise' => 30000, 'status' => true,
                ])->id,
                'cloth_service' => false,
            ]);
        });
    }
}
