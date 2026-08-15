<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The customer who paid and closed the tab.
 *
 * Nothing in the request path will ever settle that payment: the browser is
 * gone. Without this job the money sits at Razorpay while the customer is
 * chased for a renewal they already paid - which is what v1 did.
 */
class ReconcilePaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        // Live, so the gateway is really queried - against a fake, never a
        // network.
        config()->set('services.razorpay.enabled', true);
        config()->set('services.razorpay.key', 'rzp_test_key');
        config()->set('services.razorpay.secret', 'test_secret_key');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    public function test_a_payment_the_customer_never_returned_from_is_settled(): void
    {
        $subscription = $this->subscription(['status' => SubscriptionStatus::Pending, 'paid_amount_paise' => 0]);
        $payment = $this->staleAttempt($subscription);

        $this->fakeGatewayCharge($payment, 'pay_recovered');

        $this->artisan('eswachh:reconcile-payments')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame('pay_recovered', $payment->gateway_payment_id);
        $this->assertNotNull($payment->invoice_number);

        // And the subscription catches up, so nobody is chased for money they
        // have already paid.
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_an_attempt_with_no_charge_at_the_gateway_is_marked_abandoned(): void
    {
        $payment = $this->staleAttempt();

        $this->fakeNoCharges($payment);

        $this->artisan('eswachh:reconcile-payments')->assertSuccessful();

        $payment->refresh();
        // Failed, not deleted: the abandonment rate is a number worth watching.
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertNull($payment->paid_at);
    }

    public function test_a_charge_for_the_wrong_amount_is_not_banked(): void
    {
        $payment = $this->staleAttempt();

        // Razorpay holds ₹1 against an ₹850 order. Something is wrong, and
        // quietly accepting it would hide it.
        $this->fakeGatewayCharge($payment, 'pay_mismatched', amountPaise: 100);

        $this->artisan('eswachh:reconcile-payments')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertNull($payment->gateway_payment_id);
    }

    public function test_an_uncaptured_charge_is_not_treated_as_paid(): void
    {
        $payment = $this->staleAttempt();

        $this->fakeGatewayCharge($payment, 'pay_authorised', status: 'authorized');

        $this->artisan('eswachh:reconcile-payments')->assertSuccessful();

        // Authorised is not captured. The money has not actually moved.
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_a_checkout_still_in_progress_is_left_alone(): void
    {
        $payment = $this->staleAttempt();
        BranchContext::withoutScope(
            fn () => $payment->forceFill(['created_at' => Carbon::now()->subMinutes(2)])->save()
        );

        $this->artisan('eswachh:reconcile-payments')->assertSuccessful();

        // Two minutes old: the customer may still be typing their PIN. No
        // gateway call is made at all, which Http::preventStrayRequests proves.
        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        $payment = $this->staleAttempt();
        $this->fakeGatewayCharge($payment, 'pay_dryrun');

        $this->artisan('eswachh:reconcile-payments --dry-run')->assertSuccessful();

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
    }

    public function test_reconciliation_covers_every_branch(): void
    {
        $first = $this->staleAttempt();
        $second = $this->staleAttempt();

        $this->fakeGatewayCharge($first, 'pay_one');
        $this->fakeGatewayCharge($second, 'pay_two');

        $this->artisan('eswachh:reconcile-payments')->assertSuccessful();

        // A nightly job that only settled one franchise's payments would be
        // worse than none at all.
        $this->assertSame(PaymentStatus::Captured, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Captured, $second->fresh()->status);
    }

    // ---------------------------------------------------------------- helpers

    private function subscription(array $attributes = []): Subscription
    {
        return BranchContext::withoutScope(function () use ($attributes) {
            $branch = Branch::factory()->create();
            $customer = Customer::factory()->create(['branch_id' => $branch->id]);
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            return Subscription::factory()->forVehicle($vehicle)->create(array_merge([
                'duration_id' => Duration::factory()->create()->id,
            ], $attributes));
        });
    }

    /** An attempt old enough that the customer is clearly not coming back. */
    private function staleAttempt(?Subscription $subscription = null): Payment
    {
        return BranchContext::withoutScope(function () use ($subscription) {
            $subscription ??= $this->subscription();

            $payment = Payment::factory()->forSubscription($subscription)->create();
            $payment->forceFill(['created_at' => Carbon::now()->subHours(2)])->save();

            return $payment;
        });
    }

    private function fakeGatewayCharge(
        Payment $payment,
        string $gatewayPaymentId,
        ?int $amountPaise = null,
        string $status = 'captured',
    ): void {
        Http::fake([
            "*/orders/{$payment->gateway_order_id}/payments" => Http::response([
                'items' => [[
                    'id' => $gatewayPaymentId,
                    'status' => $status,
                    'amount' => $amountPaise ?? (int) $payment->amount_paise,
                    'method' => 'upi',
                    'acquirer_data' => ['upi_transaction_id' => 'UPI999'],
                ]],
            ]),
        ]);
    }

    private function fakeNoCharges(Payment $payment): void
    {
        Http::fake([
            "*/orders/{$payment->gateway_order_id}/payments" => Http::response(['items' => []]),
        ]);
    }
}
