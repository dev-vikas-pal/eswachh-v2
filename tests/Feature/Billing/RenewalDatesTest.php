<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\RecordPayment;
use App\Domain\Billing\RazorpaySignature;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * When a renewal carries on from the old date, and when it starts afresh.
 *
 * The rule lives on the model and two very different things read it: the
 * payment, which writes the dates, and the notice on four screens, which
 * promises them. This holds the two together - a customer told "the new term
 * runs to 10 Sept" and then given one that runs to 17 Sept has been misled by
 * the system rather than by anybody in it.
 */
class RenewalDatesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        SectorContext::reset();
        config()->set('services.razorpay.secret', self::SECRET);
        config()->set('services.razorpay.enabled', false);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public static function lapses(): array
    {
        return [
            // A term is one month throughout, so the switch happens once the
            // plan is more than a month past its date.
            'a fortnight early'   => [-14, 'end_date'],
            'due today'           => [0,   'end_date'],
            'a week late'         => [7,   'end_date'],
            'four weeks late'     => [28,  'end_date'],
            'two months late'     => [61,  'today'],
            'a year late'         => [365, 'today'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lapses')]
    public function test_the_screen_and_the_payment_agree_on_the_dates(int $daysLate, string $expected): void
    {
        $plan = $this->plan(Carbon::today()->subDays($daysLate));

        // What every renewal screen is about to promise.
        $promised = $plan->renewalTiming();

        $this->assertSame($expected, $promised['starts_from']);

        $payment = SectorContext::withoutScope(
            fn () => Payment::factory()->forSubscription($plan)->create()
        );

        app(RecordPayment::class)->complete([
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_payment_id' => 'pay_'.$daysLate,
            'razorpay_signature' => RazorpaySignature::sign(
                (string) $payment->gateway_order_id, 'pay_'.$daysLate, self::SECRET,
            ),
        ], []);

        $next = SectorContext::withoutScope(fn () => Subscription::query()
            ->where('vehicle_id', $plan->vehicle_id)
            ->orderByDesc('sequence')->firstOrFail());

        // What was actually written.
        $this->assertSame(
            $promised['next_period_start'],
            $next->period_start->toDateString(),
            'The dates promised on screen are not the dates the payment wrote.',
        );

        $this->assertSame($promised['next_period_end'], $next->period_end->toDateString());

        // And whichever branch it took, the customer is never sold a term that
        // has already finished.
        $this->assertTrue($next->period_end->isFuture());
    }

    private function plan(Carbon $endsOn): Subscription
    {
        return SectorContext::withoutScope(function () use ($endsOn) {
            $customer = Customer::factory()->create();
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            return Subscription::factory()->forVehicle($vehicle)->create([
                'status' => SubscriptionStatus::Active,
                'period_start' => $endsOn->copy()->subMonth(),
                'period_end' => $endsOn,
                'duration_id' => Duration::create([
                    'name' => '1 Month', 'months' => 1, 'discount_paise' => 0, 'status' => true,
                ])->id,
            ]);
        });
    }
}
