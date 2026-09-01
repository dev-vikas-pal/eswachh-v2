<?php

namespace Tests\Feature\Messaging;

use App\Domain\Billing\RazorpaySignature;
use App\Domain\Billing\RecordPayment;
use App\Enums\MessagePurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The receipt that goes out when money is taken.
 *
 * v1 sent one and v2 had the template for it sitting in the seeder with nothing
 * calling it - so a customer paid and got a "thanks for renewing" with no
 * figure, no receipt number and nothing to keep.
 *
 * The link matters as much as the message. Most customers never make an
 * account, so a receipt they can only reach by signing in is one they will
 * never open.
 */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        SectorContext::reset();
        (new MessageTemplateSeeder)->run();

        config()->set('services.razorpay.secret', self::SECRET);
        config()->set('services.razorpay.enabled', false);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_renewal_sends_a_receipt_with_the_figures_that_were_paid(): void
    {
        $plan = $this->plan();
        $payment = $this->capture($plan, 'pay_renewal');

        $receipt = $this->receiptMessage();

        $this->assertNotNull($receipt, 'No receipt was sent for a captured payment.');

        // What was taken, not what the plan costs. They can differ by the time
        // anybody reads the message, and only one of them belongs on a receipt.
        $this->assertStringContainsString(number_format($payment->amount(), 0), $receipt->body);
        $this->assertStringContainsString((string) $payment->invoice_number, $receipt->body);
    }

    public function test_the_receipt_carries_a_link_that_opens_without_an_account(): void
    {
        $plan = $this->plan();
        $payment = $this->capture($plan, 'pay_link');

        $body = $this->receiptMessage()->body;

        // Pull the link back out of the message and open it as a stranger.
        preg_match('#https?://\S+/receipt/\S+#', $body, $found);

        $this->assertNotEmpty($found, "The receipt message carries no link:\n".$body);

        $this->get($found[0])
            ->assertOk()
            ->assertSee($payment->invoice_number)
            ->assertSee('Total paid');
    }

    public function test_a_link_with_the_id_edited_is_refused(): void
    {
        $plan = $this->plan();
        $this->capture($plan, 'pay_tamper');

        $other = SectorContext::withoutScope(
            fn () => Payment::factory()->forSubscription($this->plan())->create()
        );

        preg_match('#https?://\S+/receipt/\S+#', $this->receiptMessage()->body, $found);

        // Swapping the id invalidates the signature, so one customer's link
        // cannot be edited into a look at somebody else's receipt.
        $tampered = preg_replace('#/receipt/[^?]+#', '/receipt/'.$other->id, $found[0]);

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_abandoned_checkout_gets_no_receipt(): void
    {
        $plan = $this->plan();

        SectorContext::withoutScope(
            fn () => Payment::factory()->forSubscription($plan)->create(['status' => PaymentStatus::Initiated])
        );

        // A receipt for money that never moved is a document saying otherwise.
        $this->assertNull($this->receiptMessage());
    }

    private function receiptMessage(): ?Message
    {
        return SectorContext::withoutScope(
            fn () => Message::query()->where('purpose', MessagePurpose::PaymentReceipt->value)->latest('id')->first()
        );
    }

    private function capture(Subscription $plan, string $gatewayId): Payment
    {
        $payment = SectorContext::withoutScope(
            fn () => Payment::factory()->forSubscription($plan)->create()
        );

        app(RecordPayment::class)->complete([
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_payment_id' => $gatewayId,
            'razorpay_signature' => RazorpaySignature::sign(
                (string) $payment->gateway_order_id, $gatewayId, self::SECRET,
            ),
        ], []);

        return $payment->fresh();
    }

    private function plan(): Subscription
    {
        return SectorContext::withoutScope(function () {
            $customer = Customer::factory()->create();
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            return Subscription::factory()->forVehicle($vehicle)->create([
                'status' => SubscriptionStatus::Active,
                'period_end' => Carbon::today()->addDays(20),
                'duration_id' => Duration::create([
                    'name' => '1 Month', 'months' => 1, 'discount_paise' => 0, 'status' => true,
                ])->id,
            ]);
        });
    }
}
