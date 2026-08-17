<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's period block, and the payment history behind a plan.
 *
 * The dashboard's tiles are counts of how things stand and do not move with a
 * date range - that is deliberate, and the first test pins it down so nobody
 * later "fixes" it by filtering them.
 */
class DashboardPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private Customer $customer;

    private Subscription $plan;

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
        ]);

        $this->plan = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $vehicle->id,
            'period_start' => Carbon::today(),
        ]);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_the_period_block_answers_to_the_date_range(): void
    {
        $this->paymentOn(Carbon::today(), 50000);
        $this->paymentOn(Carbon::today()->subMonths(4), 90000);

        $thisMonth = $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json('data.period');

        $this->assertSame(50000, $thisMonth['revenue_paise']);
        $this->assertSame(1, $thisMonth['payments']);

        $wider = $this->actingAs($this->owner)
            ->getJson('/api/v1/dashboard?from='.Carbon::today()->subMonths(6)->toDateString()
                .'&to='.Carbon::today()->toDateString())
            ->assertOk()
            ->json('data.period');

        $this->assertSame(140000, $wider['revenue_paise']);
        $this->assertSame(2, $wider['payments']);
    }

    public function test_the_tiles_do_not_move_with_the_date_range(): void
    {
        /*
         * On purpose. "How many plans were active last Tuesday" is not a
         * question the data can answer, so the tiles are always as things
         * stand - and the screen says so rather than implying otherwise.
         */
        $narrow = $this->actingAs($this->owner)
            ->getJson('/api/v1/dashboard?from=2000-01-01&to=2000-01-02')
            ->assertOk()
            ->json('data.subscriptions');

        $wide = $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json('data.subscriptions');

        $this->assertSame($wide, $narrow);
    }

    public function test_an_abandoned_checkout_is_not_revenue(): void
    {
        $this->paymentOn(Carbon::today(), 50000);
        $this->paymentOn(Carbon::today(), 90000, PaymentStatus::Initiated);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.revenue_paise', 50000);
    }

    public function test_the_whole_history_of_a_plan_can_be_read_at_once(): void
    {
        // The renewal case: one plan, several payments, and a customer asking
        // what they have actually paid.
        $this->paymentOn(Carbon::today()->subMonths(2), 74900);
        $this->paymentOn(Carbon::today()->subMonth(), 74900);
        $this->paymentOn(Carbon::today(), 74900);

        $rows = $this->actingAs($this->owner)
            ->getJson('/api/v1/payments?subscription_id='.$this->plan->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $rows);
    }

    public function test_a_plan_carries_its_latest_payment_for_the_list(): void
    {
        $this->paymentOn(Carbon::today()->subMonth(), 74900);
        $newest = $this->paymentOn(Carbon::today(), 84900);

        // One payment on the row, not the list: sending every payment for every
        // row of a paginated list to show the newest would be waste.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.last_payment.id', $newest->id);
    }

    private function paymentOn(Carbon $when, int $paise, PaymentStatus $status = PaymentStatus::Captured): Payment
    {
        return Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->plan->id,
            'purpose' => PaymentPurpose::Subscription,
            'status' => $status,
            'amount_paise' => $paise,
            'paid_at' => $status === PaymentStatus::Captured ? $when : null,
        ]);
    }
}
