<?php

namespace App\Domain\Billing;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\ClothBundle;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Opens a payment: writes our record, then asks the gateway for an order.
 *
 * Our row is written first so that a gateway timeout still leaves something to
 * reconcile. An order that exists at Razorpay with no matching row here is
 * money we cannot trace; a row here with no order is a harmless abandoned
 * attempt.
 */
class StartPayment
{
    public function __construct(private RazorpayGateway $gateway) {}

    /**
     * @return array{payment: Payment, checkout: array<string, mixed>}
     */
    public function forSubscription(Subscription $subscription): array
    {
        // The amount comes from the subscription, never from the request. If a
        // client could name its own price, it would - this is the single most
        // common way a payment page is abused.
        $amountPaise = (int) $subscription->amount_paise;

        $payment = DB::transaction(fn () => Payment::create([
            'branch_id' => $subscription->branch_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'purpose' => PaymentPurpose::Subscription,
            'status' => PaymentStatus::Initiated,
            'amount_paise' => $amountPaise,
            'currency' => 'INR',
            'gateway' => 'razorpay',
        ]));

        $order = $this->gateway->createOrder(
            $amountPaise,
            // Our id as the receipt, so a charge in their dashboard points
            // straight back at a row here.
            receipt: $payment->id,
            notes: [
                'subscription_id' => (string) $subscription->id,
                'branch_id' => (string) $subscription->branch_id,
            ],
        );

        $payment->forceFill(['gateway_order_id' => $order['id']])->save();

        return [
            'payment' => $payment,
            'checkout' => [
                'payment_id' => $payment->id,
                'order_id' => $order['id'],
                'amount_paise' => $amountPaise,
                'currency' => $order['currency'],
                // Public key only. The secret never leaves the server.
                'gateway_key' => config('services.razorpay.key'),
                // Tells the SPA to skip the real checkout and post a simulated
                // callback instead, so local work never touches a card.
                'simulated' => $order['simulated'],
            ],
        ];
    }

    /**
     * Open a payment for a cloth top-up.
     *
     * The amount is the bundle's price, read from the master. A client naming
     * its own amount could buy five hundred cloths for a rupee, and the credit
     * is worked out from what was paid rather than from what was asked for.
     *
     * @return array{payment: Payment, checkout: array<string, mixed>}
     */
    public function forClothTopUp(Subscription $subscription, ClothBundle $bundle): array
    {
        $amountPaise = (int) $bundle->price_paise;

        $payment = DB::transaction(fn () => Payment::create([
            'branch_id' => $subscription->branch_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'purpose' => PaymentPurpose::ClothTopUp,
            'status' => PaymentStatus::Initiated,
            'amount_paise' => $amountPaise,
            'currency' => 'INR',
            'gateway' => 'razorpay',
        ]));

        $order = $this->gateway->createOrder(
            $amountPaise,
            receipt: $payment->id,
            notes: [
                'subscription_id' => (string) $subscription->id,
                'cloth_bundle_id' => (string) $bundle->id,
            ],
        );

        $payment->forceFill(['gateway_order_id' => $order['id']])->save();

        return [
            'payment' => $payment,
            'checkout' => [
                'payment_id' => $payment->id,
                'order_id' => $order['id'],
                'amount_paise' => $amountPaise,
                'currency' => $order['currency'],
                'gateway_key' => config('services.razorpay.key'),
                'simulated' => $order['simulated'],
            ],
        ];
    }

    /**
     * Abandon an attempt the customer walked away from.
     *
     * Marked failed rather than deleted: the count of abandoned checkouts is
     * how you notice the payment page is broken.
     */
    public function abandon(Payment $payment, ?string $reason = null): void
    {
        if ($payment->status !== PaymentStatus::Initiated) {
            return;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'notes' => $reason,
        ])->save();
    }
}
