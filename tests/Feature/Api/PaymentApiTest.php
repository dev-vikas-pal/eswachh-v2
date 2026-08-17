<?php

namespace Tests\Feature\Api;

use App\Domain\Billing\RazorpaySignature;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_secret_key';

    private Branch $ourBranch;

    private Branch $theirBranch;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        config()->set('services.razorpay.secret', self::SECRET);
        config()->set('services.razorpay.key', 'rzp_test_key');
        config()->set('services.razorpay.enabled', false);

        $this->ourBranch = Branch::factory()->create(['code' => 'AAA']);
        $this->theirBranch = Branch::factory()->create(['code' => 'BBB']);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_payments_require_signing_in(): void
    {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
    }

    public function test_a_franchise_owner_only_sees_their_own_branch_payments(): void
    {
        SectorContext::withoutScope(function () {
            Payment::factory()->captured()->count(3)->create(['branch_id' => $this->ourBranch->id]);
            Payment::factory()->captured()->count(5)->create(['branch_id' => $this->theirBranch->id]);
        });

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/payments')->assertOk();

        // Three, not eight. The scope filters; there is no branch parameter to
        // widen it with.
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_a_super_admin_sees_every_branch(): void
    {
        SectorContext::withoutScope(function () {
            Payment::factory()->captured()->count(3)->create(['branch_id' => $this->ourBranch->id]);
            Payment::factory()->captured()->count(5)->create(['branch_id' => $this->theirBranch->id]);
        });

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('meta.total', 8);
    }

    public function test_a_payment_from_another_branch_is_not_found_rather_than_forbidden(): void
    {
        $theirs = SectorContext::withoutScope(
            fn () => Payment::factory()->captured()->create(['branch_id' => $this->theirBranch->id])
        );

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        // 404, not 403: a 403 confirms the record exists, which is itself a
        // leak about another franchise's business.
        $this->actingAs($owner)->getJson("/api/v1/payments/{$theirs->id}")->assertNotFound();
    }

    public function test_the_reported_total_only_counts_money_actually_taken(): void
    {
        SectorContext::withoutScope(function () {
            Payment::factory()->captured()->create(['branch_id' => $this->ourBranch->id, 'amount_paise' => 85000]);
            Payment::factory()->captured()->create(['branch_id' => $this->ourBranch->id, 'amount_paise' => 15000]);
            Payment::factory()->create(['branch_id' => $this->ourBranch->id, 'amount_paise' => 99900]);
        });

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            // Three rows on screen, but only ₹1,000 of income.
            ->assertJsonPath('meta.total_captured_paise', 100000);
    }

    public function test_the_total_is_the_same_on_every_page(): void
    {
        SectorContext::withoutScope(
            fn () => Payment::factory()->captured()->count(6)->create([
                'branch_id' => $this->ourBranch->id, 'amount_paise' => 10000,
            ])
        );

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        // The total describes the filter, not the page. Taking it after
        // paginating would read zero here, because the offset lands past the
        // single row an aggregate returns.
        foreach ([1, 2, 3] as $page) {
            $this->actingAs($owner)->getJson("/api/v1/payments?per_page=2&page={$page}")
                ->assertOk()
                ->assertJsonPath('meta.total_captured_paise', 60000);
        }
    }

    public function test_starting_a_payment_uses_the_price_on_record_not_the_one_sent(): void
    {
        $subscription = $this->subscription($this->ourBranch, ['amount_paise' => 85000]);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/pay", ['amount_paise' => 100])
            ->assertCreated();

        // The one rupee in the request body is ignored entirely.
        $this->assertSame(85000, $response->json('data.amount_paise'));

        $payment = SectorContext::withoutScope(fn () => Payment::query()->firstOrFail());
        $this->assertSame(85000, (int) $payment->amount_paise);
        $this->assertSame(PaymentStatus::Initiated, $payment->status);
    }

    public function test_the_public_key_is_sent_to_the_browser_but_never_the_secret(): void
    {
        $subscription = $this->subscription($this->ourBranch);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/pay")
            ->assertCreated();

        $this->assertSame('rzp_test_key', $response->json('data.gateway_key'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_a_payment_cannot_be_started_for_another_branch(): void
    {
        $theirs = $this->subscription($this->theirBranch);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)
            ->postJson("/api/v1/subscriptions/{$theirs->id}/pay")
            ->assertNotFound();

        $this->assertSame(0, SectorContext::withoutScope(fn () => Payment::query()->count()));
    }

    public function test_the_callback_needs_no_session_but_does_need_a_signature(): void
    {
        $payment = $this->initiatedPayment($this->ourBranch);

        // Signed in as nobody, exactly as Razorpay would post.
        $this->postJson('/api/v1/payments/callback', [
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_payment_id' => 'pay_from_gateway',
            'razorpay_signature' => RazorpaySignature::sign(
                (string) $payment->gateway_order_id, 'pay_from_gateway', self::SECRET
            ),
        ])->assertOk()->assertJsonPath('result', 'captured');

        $this->assertSame(PaymentStatus::Captured, $payment->fresh()->status);
    }

    public function test_an_unsigned_callback_is_refused(): void
    {
        $payment = $this->initiatedPayment($this->ourBranch);

        $this->postJson('/api/v1/payments/callback', [
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_payment_id' => 'pay_forged',
            'razorpay_signature' => 'not-a-signature',
        ])->assertStatus(422)->assertJsonPath('result', 'rejected');

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
    }

    public function test_a_cleaner_cannot_record_a_payment_by_hand(): void
    {
        $subscription = $this->subscription($this->ourBranch);
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($cleaner)->postJson('/api/v1/payments/manual', [
            'subscription_id' => $subscription->id,
            'amount_paise' => 85000,
            'method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_a_manual_payment_records_who_took_it(): void
    {
        $subscription = $this->subscription($this->ourBranch);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/payments/manual', [
            'subscription_id' => $subscription->id,
            'amount_paise' => 85000,
            'method' => 'cash',
            'reference' => 'receipt 118',
            'paid_at' => now()->subHour()->toDateTimeString(),
        ])->assertCreated();

        $this->assertTrue($response->json('data.recorded_by_hand'));
        $this->assertNotNull($response->json('data.invoice_number'));

        $payment = SectorContext::withoutScope(fn () => Payment::query()->firstOrFail());
        // Cash is where money goes missing, so the taker is always on record.
        $this->assertSame($owner->id, $payment->verified_by);
    }

    public function test_recording_a_cash_payment_moves_the_plan_on(): void
    {
        $subscription = $this->subscription($this->ourBranch, [
            'status' => \App\Enums\SubscriptionStatus::Pending,
            'paid_amount_paise' => 0,
        ]);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/payments/manual', [
            'subscription_id' => $subscription->id,
            'amount_paise' => 85000,
            'method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
        ])->assertCreated();

        // Without this the money is banked and the plan stays pending, so the
        // round never picks the car up and the nightly job chases somebody who
        // has already handed over the money.
        $this->assertSame('active', $response->json('subscription.status'));
        $this->assertSame(
            \App\Enums\SubscriptionStatus::Active,
            $subscription->fresh()->status
        );
    }

    public function test_a_payment_can_be_recorded_without_moving_the_plan(): void
    {
        $subscription = $this->subscription($this->ourBranch, [
            'status' => \App\Enums\SubscriptionStatus::Pending,
        ]);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->postJson('/api/v1/payments/manual', [
            'subscription_id' => $subscription->id,
            'amount_paise' => 85000,
            'method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
            // For a payment that was already applied: extending a second time
            // would give the customer a free period.
            'extend' => false,
        ])->assertCreated();

        $this->assertSame(
            \App\Enums\SubscriptionStatus::Pending,
            $subscription->fresh()->status
        );
    }

    public function test_a_backdated_payment_in_the_future_is_refused(): void
    {
        $subscription = $this->subscription($this->ourBranch);
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->postJson('/api/v1/payments/manual', [
            'subscription_id' => $subscription->id,
            'amount_paise' => 85000,
            'method' => 'cash',
            'paid_at' => now()->addWeek()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('paid_at');
    }

    public function test_the_summary_reports_abandoned_attempts_beside_revenue(): void
    {
        SectorContext::withoutScope(function () {
            Payment::factory()->captured()->create([
                'branch_id' => $this->ourBranch->id, 'amount_paise' => 85000,
            ]);
            Payment::factory()->count(2)->create(['branch_id' => $this->ourBranch->id]);
        });

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->getJson('/api/v1/payments/summary')
            ->assertOk()
            ->assertJsonPath('data.captured_paise', 85000)
            ->assertJsonPath('data.captured_count', 1)
            // Surfaced next to revenue, because a jump here means the payment
            // page is broken and nobody would find it in a log.
            ->assertJsonPath('data.abandoned_count', 2);
    }

    // ---------------------------------------------------------------- helpers

    private function subscription(Branch $branch, array $attributes = []): Subscription
    {
        return SectorContext::withoutScope(function () use ($branch, $attributes) {
            $customer = Customer::factory()->create(['branch_id' => $branch->id]);
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            return Subscription::factory()->forVehicle($vehicle)->create(array_merge([
                'duration_id' => Duration::factory()->create()->id,
            ], $attributes));
        });
    }

    private function initiatedPayment(Branch $branch): Payment
    {
        return SectorContext::withoutScope(
            fn () => Payment::factory()->forSubscription($this->subscription($branch))->create()
        );
    }
}
