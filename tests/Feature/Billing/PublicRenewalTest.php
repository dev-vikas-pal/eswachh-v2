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
 * Renewing by car number, with nobody signed in.
 *
 * This page is the only unauthenticated route that can reach a customer record,
 * and it runs with the sector scope covering nothing - which is exactly the
 * condition every test here exists to hold it to. A scope that quietly returns
 * null instead of a car does not throw; it under-prices a renewal and shows an
 * empty card, and both of those reached a customer.
 */
class PublicRenewalTest extends TestCase
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

    public function test_the_price_includes_the_car_and_the_society_although_nobody_is_signed_in(): void
    {
        /*
         * The bug this pins, in full.
         *
         * The lookup loaded the vehicle and the customer inside the sector
         * scope. With no signed in user that scope covers nothing, so both came
         * back null - and the price book, asked to price a plan with no car and
         * no address, quietly dropped the vehicle category and the society
         * surcharge and answered with what was left. A customer was shown ₹225
         * for a renewal, clicked Pay, and Razorpay asked them for ₹1,872.
         */
        $this->plan();

        $response = $this->postJson('/api/v1/public/renew/lookup', ['registration' => 'UP42BJ9003']);

        $response->assertOk();

        // 600 car + 200 package + 100 interior + 150 surcharge = 1050 a month,
        // over three months, less the 300 discount.
        $this->assertEquals(2850, $response->json('data.amount'));

        // Named line by line, because a total that is right by accident is the
        // failure mode here: drop the car and the society and this still adds
        // up to a number, just the wrong one.
        $labels = array_column($response->json('data.lines'), 'source');
        $this->assertContains('category', $labels, 'The car was priced out of the quote.');
        $this->assertContains('society', $labels, 'The society surcharge was priced out of the quote.');

        // And the card can actually show whose plan it is.
        $this->assertSame('UP42BJ9003', $response->json('data.registration'));
        $this->assertSame('Asha', $response->json('data.name'));
    }

    public function test_the_gateway_is_asked_for_the_figure_the_page_quoted(): void
    {
        /*
         * The other half of the same defect. Even once the quote was right, the
         * payment was opened from the plan's stored amount - what it cost the
         * last time it was bought - so the two numbers could still disagree.
         */
        $plan = $this->plan();

        // Whatever it cost last year is not what it costs now.
        SectorContext::withoutScope(
            fn () => $plan->forceFill(['amount_paise' => 187200])->save()
        );

        $quoted = $this->postJson('/api/v1/public/renew/lookup', ['registration' => 'UP42BJ9003'])
            ->json('data.amount');

        $opened = $this->postJson('/api/v1/public/renew/pay', [
            'subscription_id' => $plan->id,
            'registration' => 'UP42BJ9003',
        ]);

        $opened->assertCreated();

        $this->assertSame((int) round($quoted * 100), $opened->json('data.amount_paise'));

        $payment = SectorContext::withoutScope(fn () => Payment::query()->latest('created_at')->first());
        $this->assertSame((int) round($quoted * 100), (int) $payment->amount_paise);
        $this->assertSame(PaymentStatus::Initiated, $payment->status);
    }

    public function test_a_plan_still_in_date_says_so_without_refusing_the_renewal(): void
    {
        $plan = $this->plan();

        SectorContext::withoutScope(
            fn () => $plan->forceFill(['period_end' => Carbon::today()->addDays(34)])->save()
        );

        $timing = $this->postJson('/api/v1/public/renew/lookup', ['registration' => 'UP42BJ9003'])
            ->assertOk()
            ->json('data.timing');

        $this->assertTrue($timing['early']);
        $this->assertSame(34, $timing['days_remaining']);
        $this->assertFalse($timing['overdue']);

        // Said, not enforced. Renewing early is a good thing for a customer to
        // do and the period is extended from its end date, so refusing would
        // only mean the money arrives later.
        $this->postJson('/api/v1/public/renew/pay', [
            'subscription_id' => $plan->id,
            'registration' => 'UP42BJ9003',
        ])->assertCreated();
    }

    public function test_a_lapsed_plan_is_counted_the_other_way(): void
    {
        $plan = $this->plan();

        SectorContext::withoutScope(
            fn () => $plan->forceFill(['period_end' => Carbon::today()->subDays(9)])->save()
        );

        $timing = $this->postJson('/api/v1/public/renew/lookup', ['registration' => 'UP42BJ9003'])
            ->json('data.timing');

        $this->assertTrue($timing['overdue']);
        $this->assertSame(9, $timing['days_overdue']);
        $this->assertFalse($timing['early']);
    }

    public function test_a_car_that_is_not_ours_is_answered_the_same_way_as_one_with_no_plan(): void
    {
        $this->plan();

        // Telling the two apart would confirm which cars are customers.
        $unknown = $this->postJson('/api/v1/public/renew/lookup', ['registration' => 'UP99XX0000']);

        $unknown->assertNotFound();
        $this->assertFalse($unknown->json('found'));
    }

    public function test_the_car_number_is_checked_against_the_plan_before_a_payment_opens(): void
    {
        $plan = $this->plan();

        // An id on its own would be enough to open a payment for any plan, and
        // ids leak far more easily than the pairing of the two does.
        $this->postJson('/api/v1/public/renew/pay', [
            'subscription_id' => $plan->id,
            'registration' => 'UP42BJ0000',
        ])->assertNotFound();

        $this->assertSame(0, SectorContext::withoutScope(fn () => Payment::query()->count()));
    }

    /**
     * A running plan on a car, priced from every master a renewal touches.
     *
     * Built through withoutScope because there is no signed in user in any of
     * these tests - which is the whole point of them.
     */
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
