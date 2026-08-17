<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\RazorpaySignature;
use App\Domain\Billing\RecordPayment;
use App\Domain\Billing\StartPayment;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The rules money has to obey.
 *
 * Each test here corresponds to something v1 got wrong in production, so a
 * failure means a real defect has come back, not that an assertion is fussy.
 */
class PaymentCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.razorpay.secret', self::SECRET);
        config()->set('services.razorpay.key', 'rzp_test_key');
        config()->set('services.razorpay.enabled', false);
    }

    public function test_a_forged_callback_is_rejected(): void
    {
        $payment = $this->initiatedPayment();

        $outcome = app(RecordPayment::class)->complete([
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_payment_id' => 'pay_forged',
            'razorpay_signature' => 'nonsense',
        ], []);

        $this->assertSame('rejected', $outcome->result);
        $this->assertFalse($outcome->succeeded());

        // Nothing moved: no money banked, no period extended.
        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
    }

    public function test_a_callback_with_no_signature_is_rejected(): void
    {
        $payment = $this->initiatedPayment();

        $outcome = app(RecordPayment::class)->complete([
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_payment_id' => 'pay_unsigned',
        ], []);

        // A missing signature must fail closed. Treating "no signature" as
        // "nothing to check" is how an unverified gateway gets shipped.
        $this->assertSame('rejected', $outcome->result);
    }

    public function test_a_signature_from_a_different_secret_is_rejected(): void
    {
        $payment = $this->initiatedPayment();

        $outcome = app(RecordPayment::class)->complete($this->callbackFor(
            $payment, 'pay_wrongsecret', secret: 'somebody_elses_secret'
        ), []);

        $this->assertSame('rejected', $outcome->result);
        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
    }

    public function test_a_valid_callback_banks_the_money_and_extends_the_period(): void
    {
        $subscription = $this->subscription(['status' => SubscriptionStatus::Pending, 'paid_amount_paise' => 0]);
        $payment = $this->initiatedPayment($subscription);

        $outcome = app(RecordPayment::class)->complete(
            $this->callbackFor($payment, 'pay_good'),
            ['method' => 'upi', 'reference' => 'UPI123456']
        );

        $this->assertSame('captured', $outcome->result);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame('pay_good', $payment->gateway_payment_id);
        $this->assertSame('upi', $payment->method);
        $this->assertSame('UPI123456', $payment->reference);
        $this->assertNotNull($payment->paid_at);

        // A first payment turns the pending period live rather than adding a
        // second one alongside it.
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(1, Subscription::withoutGlobalScope('sector')->count());
    }

    public function test_every_captured_payment_gets_an_invoice_number(): void
    {
        $payment = $this->initiatedPayment();

        app(RecordPayment::class)->complete($this->callbackFor($payment, 'pay_invoice'), []);

        $this->assertNotNull($payment->fresh()->invoice_number);
    }

    public function test_invoice_numbers_run_in_one_unbroken_sequence(): void
    {
        /*
         * One series for the business, not one per territory.
         *
         * It used to run per branch, prefixed with that branch's code. Sectors
         * are the wrong replacement: somebody covering three of them would have
         * their invoices split across three runs, and an accountant reading one
         * would find gaps that are not gaps. The prefix now comes from the
         * invoice_prefix setting.
         */
        SiteSettings::put(['invoice_prefix' => 'GN1']);

        $numbers = collect(range(1, 3))->map(function () {
            $payment = $this->initiatedPayment($this->subscription());
            app(RecordPayment::class)->complete(
                $this->callbackFor($payment, 'pay_'.uniqid()), []
            );

            return $payment->fresh()->invoice_number;
        });

        // No gaps: an accountant asking "where is 00002" must never be right.
        $this->assertSame(
            $numbers->first() ? [1, 2, 3] : [],
            $numbers->map(fn ($n) => (int) substr($n, strrpos($n, '/') + 1))->all()
        );
        $this->assertStringStartsWith('GN1/', $numbers->first());
    }

    public function test_the_sequence_does_not_restart_for_a_different_sector(): void
    {
        // Two customers in different sectors, one run of numbers. The old
        // per-branch behaviour would have given both an 00001.
        $first = $this->initiatedPayment($this->subscription());
        app(RecordPayment::class)->complete($this->callbackFor($first, 'pay_'.uniqid()), []);

        $elsewhere = Sector::factory()->create();
        $second = $this->initiatedPayment($this->subscription([], null, $elsewhere->id));
        app(RecordPayment::class)->complete($this->callbackFor($second, 'pay_'.uniqid()), []);

        $this->assertNotSame($first->fresh()->invoice_number, $second->fresh()->invoice_number);
    }

    public function test_the_same_callback_twice_only_takes_the_money_once(): void
    {
        $subscription = $this->subscription();
        $payment = $this->initiatedPayment($subscription);
        $callback = $this->callbackFor($payment, 'pay_repeat');

        $first = app(RecordPayment::class)->complete($callback, []);
        $second = app(RecordPayment::class)->complete($callback, []);

        $this->assertSame('captured', $first->result);
        $this->assertSame('already_handled', $second->result);

        // The second attempt is reported as a success to the customer - their
        // payment did go through - while changing nothing.
        $this->assertTrue($second->succeeded());

        $this->assertSame(1, Payment::withoutGlobalScope('sector')
            ->where('status', PaymentStatus::Captured)->count());
    }

    public function test_a_replayed_callback_cannot_extend_the_period_twice(): void
    {
        $subscription = $this->subscription(['period_end' => Carbon::today()->addDays(10)]);
        $payment = $this->initiatedPayment($subscription);
        $callback = $this->callbackFor($payment, 'pay_replay');

        app(RecordPayment::class)->complete($callback, []);
        $periodsAfterFirst = Subscription::withoutGlobalScope('sector')->count();

        app(RecordPayment::class)->complete($callback, []);
        app(RecordPayment::class)->complete($callback, []);

        $this->assertSame(
            $periodsAfterFirst,
            Subscription::withoutGlobalScope('sector')->count(),
            'A replayed callback must not add another paid period.'
        );
    }

    public function test_the_database_refuses_a_duplicate_gateway_payment_id(): void
    {
        Payment::factory()->captured()->create(['gateway_payment_id' => 'pay_unique']);

        // Belt and braces: even with every check above it removed, the unique
        // key stops the same charge being recorded twice.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Payment::factory()->captured()->create(['gateway_payment_id' => 'pay_unique']);
    }

    public function test_a_callback_for_an_unknown_order_is_reported_not_swallowed(): void
    {
        $outcome = app(RecordPayment::class)->complete([
            'razorpay_order_id' => 'order_never_seen',
            'razorpay_payment_id' => 'pay_orphan',
            'razorpay_signature' => RazorpaySignature::sign('order_never_seen', 'pay_orphan', self::SECRET),
        ], []);

        $this->assertSame('rejected', $outcome->result);
        // The customer is told their money was received and someone will be in
        // touch, rather than being shown a generic failure for a real payment.
        $this->assertStringContainsString('could not match', $outcome->message);
    }

    public function test_a_period_that_has_been_renewed_cannot_be_paid_for_again(): void
    {
        $first = $this->subscription(['period_end' => Carbon::today()->addDays(10)]);

        app(RecordPayment::class)->complete(
            $this->callbackFor($this->initiatedPayment($first), 'pay_one'), []
        );

        /*
         * The office clicks "take payment" on whichever row is in front of
         * them, and a finished period looks exactly like a current one in a
         * list. Paying against the old row created a second live period beside
         * the real one - the same car with two plans, ending on the same day,
         * billed twice and cleaned once.
         */
        $this->expectException(ValidationException::class);

        app(StartPayment::class)->forSubscription($first->fresh());
    }

    public function test_a_payment_that_reaches_a_superseded_period_extends_the_live_one(): void
    {
        $first = $this->subscription(['period_end' => Carbon::today()->addDays(10)]);

        // Opened while the period was current, captured after it was renewed -
        // the race the guard above cannot catch, so the capture has to cope.
        $stale = $this->initiatedPayment($first);

        app(RecordPayment::class)->complete(
            $this->callbackFor($this->initiatedPayment($first), 'pay_one'), []
        );

        app(RecordPayment::class)->complete($this->callbackFor($stale, 'pay_two'), []);

        $periods = Subscription::withoutGlobalScope('sector')
            ->where('vehicle_id', $first->vehicle_id)
            ->get();

        // Three periods, one live. Never two live ones.
        $this->assertCount(3, $periods);
        $this->assertCount(1, $periods->where('status', SubscriptionStatus::Active));

        // And every period number is its own.
        $this->assertSame(
            $periods->pluck('sequence')->sort()->values()->all(),
            $periods->pluck('sequence')->unique()->sort()->values()->all(),
        );
    }

    public function test_renewing_early_adds_time_rather_than_losing_it(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Active,
            'period_end' => Carbon::today()->addDays(10),
        ]);
        $payment = $this->initiatedPayment($subscription);

        app(RecordPayment::class)->complete($this->callbackFor($payment, 'pay_early'), []);

        $next = Subscription::withoutGlobalScope('sector')
            ->where('sequence', 2)->firstOrFail();

        // The new period starts where the old one ends, so a customer who pays
        // ten days early keeps those ten days.
        $this->assertTrue($next->period_start->isSameDay(Carbon::today()->addDays(10)));
        $this->assertTrue($next->period_end->isSameDay(Carbon::today()->addDays(10)->addMonth()));

        // And the period just paid off is closed, not left running alongside.
        $this->assertSame(SubscriptionStatus::Ended, $subscription->fresh()->status);
    }

    public function test_renewing_after_lapsing_restarts_from_today(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Active,
            'period_start' => Carbon::today()->subMonths(2),
            'period_end' => Carbon::today()->subDays(20),
        ]);
        $payment = $this->initiatedPayment($subscription);

        app(RecordPayment::class)->complete($this->callbackFor($payment, 'pay_late'), []);

        $next = Subscription::withoutGlobalScope('sector')->where('sequence', 2)->firstOrFail();

        // A customer who lapsed for twenty days does not get billed for a
        // period that has already gone by.
        $this->assertTrue($next->period_start->isSameDay(Carbon::today()));
    }

    public function test_the_money_is_recorded_even_when_the_period_cannot_be_extended(): void
    {
        $subscription = $this->subscription();
        $payment = $this->initiatedPayment($subscription);

        // The subscription vanishes between the charge and the renewal - the
        // exact shape of the v1 failure where money was taken and nothing
        // recorded it.
        Subscription::withoutGlobalScope('sector')->whereKey($subscription->id)->forceDelete();

        $outcome = app(RecordPayment::class)->complete($this->callbackFor($payment, 'pay_orphaned'), []);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Captured, $payment->status, 'The money must be on the books regardless.');
        $this->assertNotNull($payment->invoice_number);
        $this->assertTrue($outcome->succeeded());
    }

    public function test_only_captured_payments_count_as_revenue(): void
    {
        SectorContext::withoutScope(function () {
            $branch = Branch::factory()->create();

            Payment::factory()->captured()->create(['branch_id' => $branch->id, 'amount_paise' => 50000]);
            Payment::factory()->create(['branch_id' => $branch->id, 'amount_paise' => 99900]);
            Payment::factory()->failed()->create(['branch_id' => $branch->id, 'amount_paise' => 99900]);

            // An abandoned checkout is not income. v1 counted from a table that
            // mixed both and reported revenue that had never arrived.
            $this->assertSame(50000, (int) Payment::query()->revenue()->sum('amount_paise'));
        });
    }

    // ---------------------------------------------------------------- helpers

    private function subscription(array $attributes = [], ?Branch $branch = null, ?string $sectorId = null): Subscription
    {
        return SectorContext::withoutScope(function () use ($attributes, $branch, $sectorId) {
            $branch ??= Branch::factory()->create();
            $customer = Customer::factory()->create(array_filter([
                'branch_id' => $branch->id,
                'sector_id' => $sectorId,
            ]));
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            return Subscription::factory()
                ->forVehicle($vehicle)
                ->create(array_merge([
                    'duration_id' => Duration::factory()->create()->id,
                ], $attributes));
        });
    }

    private function initiatedPayment(?Subscription $subscription = null): Payment
    {
        return SectorContext::withoutScope(function () use ($subscription) {
            $subscription ??= $this->subscription();

            return Payment::factory()->forSubscription($subscription)->create();
        });
    }

    /**
     * A callback signed the way Razorpay would sign it.
     *
     * @return array<string, string>
     */
    private function callbackFor(Payment $payment, string $gatewayPaymentId, ?string $secret = null): array
    {
        $orderId = (string) $payment->gateway_order_id;

        return [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $gatewayPaymentId,
            'razorpay_signature' => RazorpaySignature::sign(
                $orderId, $gatewayPaymentId, $secret ?? self::SECRET
            ),
        ];
    }
}
