<?php

namespace App\Domain\Billing;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ClothBundle;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Tenancy\SectorContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /**
     * The territory this money is being taken in.
     *
     * Read outside the scope, deliberately. A public signup has nobody signed
     * in, so the scope is restricted with no sectors and the customer relation
     * comes back empty - and `?->sector_id` then quietly yields null. Every
     * payment taken from the public form was stamped with nothing and became
     * invisible to the very franchise that had just earned it.
     *
     * The one place a null stamp is still correct is a customer with no sector
     * at all, which is a different problem and shows up on the customer record.
     */
    private function sectorFor(Subscription $subscription): ?string
    {
        return SectorContext::withoutScope(
            fn () => Customer::withTrashed()->whereKey($subscription->customer_id)->value('sector_id')
        );
    }

    public function __construct(private RazorpayGateway $gateway) {}

    /**
     * @return array{payment: Payment, checkout: array<string, mixed>}
     */
    public function forSubscription(Subscription $subscription): array
    {
        /*
         * A period that has already been renewed is not the one to pay for.
         *
         * Stopped here rather than sorted out afterwards: the office clicks
         * "take payment" on whichever row is in front of them, and an old row
         * looks exactly like a current one in a list. Refusing before the
         * gateway opens is better than taking money and reasoning about which
         * period it belonged to.
         */
        if ($subscription->status === SubscriptionStatus::Ended) {
            /*
             * Read outside the scope, like the stamp below. This runs from the
             * public renewal page as well as the office, and with nobody signed
             * in a scoped query finds no live period - so the guard would pass
             * exactly when it is needed most.
             */
            $live = SectorContext::withoutScope(fn () => Subscription::query()
                ->where('vehicle_id', $subscription->vehicle_id)
                ->whereNot('id', $subscription->id)
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Hold, SubscriptionStatus::Pending])
                ->exists());

            if ($live) {
                throw ValidationException::withMessages([
                    'subscription' => 'This period has already been renewed. Take the payment on the current one.',
                ]);
            }
        }

        // The amount comes from the subscription, never from the request. If a
        // client could name its own price, it would - this is the single most
        // common way a payment page is abused.
        $amountPaise = (int) $subscription->amount_paise;

        $payment = DB::transaction(fn () => Payment::create([
            /*
             * The territory this money was taken in, stamped once.
             *
             * Read from the customer now rather than derived on every later
             * query, because a payment records something that happened: hand
             * the sector to somebody else tomorrow and they get the customer
             * and the plan, but not last year's revenue.
             */
            'sector_id' => $this->sectorFor($subscription),
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
                'sector_id' => (string) $this->sectorFor($subscription),
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
            /*
             * The territory this money was taken in, stamped once.
             *
             * Read from the customer now rather than derived on every later
             * query, because a payment records something that happened: hand
             * the sector to somebody else tomorrow and they get the customer
             * and the plan, but not last year's revenue.
             */
            'sector_id' => $this->sectorFor($subscription),
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
