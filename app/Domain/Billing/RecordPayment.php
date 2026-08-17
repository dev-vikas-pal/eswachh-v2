<?php

namespace App\Domain\Billing;

use App\Domain\Cloth\ClothLedger;
use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\WelcomeToEswachh;
use App\Models\ClothBundle;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Turns a gateway callback into money on the books and, if all is well, a
 * renewed subscription.
 *
 * The order of operations is the whole point:
 *
 *   1. the payment is recorded first, because the money has already left the
 *      customer and must exist in our records whatever happens next
 *   2. only then is the subscription extended
 *
 * v1 did the reverse and wrapped both in a try/catch that returned early, so a
 * failure while updating the order meant the payment was never written at all.
 * Money was taken and nothing recorded it.
 */
class RecordPayment
{
    /**
     * @param  array<string, mixed>  $callback  As posted by the gateway
     * @param  array<string, mixed>  $details   Fetched from the gateway API
     */
    public function complete(array $callback, array $details): PaymentOutcome
    {
        if (! RazorpaySignature::isValid($callback)) {
            // Checked before anything else, and before the branch scope is
            // lifted below. An unverified callback never reaches a query.
            return PaymentOutcome::rejected('This payment could not be verified.');
        }

        /*
         * The gateway posts here with no session, so there is no user and the
         * branch scope would hide every record - the payment, the subscription,
         * all of it - and quietly do nothing.
         *
         * The authority here is the payment's own branch_id, established when
         * the customer was sent to the gateway by an authenticated request, not
         * the caller's identity. So this runs deliberately unscoped, and every
         * row it touches is reached through that payment.
         */
        return SectorContext::withoutScope(fn () => $this->capturePayment($callback, $details));
    }

    /**
     * @param  array<string, mixed>  $callback
     * @param  array<string, mixed>  $details
     */
    private function capturePayment(array $callback, array $details): PaymentOutcome
    {
        $gatewayPaymentId = (string) ($callback['razorpay_payment_id'] ?? '');
        $gatewayOrderId = (string) ($callback['razorpay_order_id'] ?? '');

        // Already dealt with? Say so and stop. A refreshed browser or a gateway
        // retry must not renew twice.
        $existing = Payment::query()
            ->where('gateway_payment_id', $gatewayPaymentId)
            ->where('status', PaymentStatus::Captured)
            ->first();

        if ($existing) {
            Log::info('Ignoring a repeat payment callback.', ['gateway_payment_id' => $gatewayPaymentId]);

            return PaymentOutcome::alreadyHandled($existing);
        }

        $payment = Payment::query()
            ->where('gateway_order_id', $gatewayOrderId)
            ->where('status', PaymentStatus::Initiated)
            ->first();

        if (! $payment) {
            Log::error('A payment arrived with no matching initiated record.', [
                'gateway_order_id' => $gatewayOrderId,
                'gateway_payment_id' => $gatewayPaymentId,
            ]);

            return PaymentOutcome::rejected(
                'Your payment was received but we could not match it to an order. Our team will contact you.'
            );
        }

        try {
            $this->capture($payment, $gatewayPaymentId, $details);
        } catch (QueryException $e) {
            // The unique key on gateway_payment_id fired, meaning a concurrent
            // callback captured it first. That is a success, not an error.
            if ($this->isDuplicate($e)) {
                return PaymentOutcome::alreadyHandled($payment->fresh());
            }

            throw $e;
        }

        // The subscription is extended only after the money is on the books.
        // A failure here leaves a recorded payment that can be reconciled, and
        // never a silent loss.
        try {
            match ($payment->purpose) {
                PaymentPurpose::Subscription => $this->extendSubscription($payment),
                // v1 took the money for twenty two cloth top-ups and never
                // credited one of them. Here the credit is part of completing
                // the payment, not a separate step somebody has to remember.
                PaymentPurpose::ClothTopUp => $this->creditCloths($payment),
            };

            /*
             * Told after the plan has actually moved, so a message never
             * promises something the record does not show. Messaging failures
             * are swallowed inside the notifier: not telling somebody is bad,
             * but losing a captured payment over it is worse.
             */
            $this->announce($payment);
        } catch (\Throwable $e) {
            Log::error('Payment captured but the subscription could not be extended.', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return PaymentOutcome::capturedButIncomplete($payment->fresh());
        }

        return PaymentOutcome::captured($payment->fresh());
    }

    /**
     * Move a subscription on for a payment that reconciliation settled.
     *
     * There is no callback and no signature here - the gateway's own API told
     * us over an authenticated connection - so the payment is already captured
     * and only the period has to catch up.
     */
    public function extendAfterReconciliation(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Captured) {
            return;
        }

        try {
            // Same reasoning as complete(): a console command has no user, and
            // the payment's own branch is the authority.
            SectorContext::withoutScope(function () use ($payment) {
                match ($payment->purpose) {
                    PaymentPurpose::Subscription => $this->extendSubscription($payment),
                    // A cloth top-up taken in cash still has to credit the
                    // cloths. This path was subscription-only, so an office
                    // recording a cash top-up banked the money and gave nothing.
                    PaymentPurpose::ClothTopUp => $this->creditCloths($payment),
                };

                /*
                 * And the customer is told, exactly as they would be for a
                 * payment taken online. Somebody who pays the cleaner in cash
                 * should not get a worse experience than somebody who pays by
                 * card - that difference is invisible to them and looks like
                 * the message was simply forgotten.
                 */
                $this->announce($payment);
            });
        } catch (\Throwable $e) {
            // Never abort the whole reconciliation run because one
            // subscription would not move; the money is already safely
            // recorded and the next run will retry.
            Log::error('Reconciliation banked a payment but could not extend the subscription.', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write the money down.
     *
     * @param  array<string, mixed>  $details
     */
    private function capture(Payment $payment, string $gatewayPaymentId, array $details): void
    {
        DB::transaction(function () use ($payment, $gatewayPaymentId, $details) {
            $payment->forceFill([
                'gateway_payment_id' => $gatewayPaymentId,
                'status' => PaymentStatus::Captured,
                'method' => $details['method'] ?? null,
                'reference' => $details['reference'] ?? null,
                // The moment the money moved, recorded once and never rewritten.
                'paid_at' => now(),
                'invoice_number' => $payment->invoice_number ?? InvoiceNumber::next(),
            ])->save();
        });
    }

    /**
     * Put the cloths on the subscription that the customer just paid for.
     *
     * The bundle comes from the price paid, not from the request: a client that
     * could name its own bundle could buy five hundred cloths for a rupee.
     */
    private function creditCloths(Payment $payment): void
    {
        $subscription = $payment->subscription;

        if (! $subscription) {
            return;
        }

        $bundle = ClothBundle::query()
            ->where('price_paise', $payment->amount_paise)
            ->where('status', true)
            ->first();

        if (! $bundle) {
            // Rather than guess at a quantity, leave it for a person and say
            // so loudly. The money is already recorded either way.
            Log::error('A cloth top-up was paid for with no bundle at that price.', [
                'payment_id' => $payment->id,
                'amount_paise' => $payment->amount_paise,
            ]);

            throw new \RuntimeException('No cloth bundle matches the amount paid.');
        }

        app(ClothLedger::class)->purchase($subscription, $bundle, $payment);
    }

    /**
     * Move the subscription on by the period that was paid for.
     *
     * Renewal adds the next period rather than editing this one, so the history
     * of a vehicle stays readable and revenue reconciles against periods.
     */
    /**
     * Tell the customer - and, for a first payment, the office.
     *
     * The three messages the requirements document numbers [1], [2], [3] and
     * [4]. Which one goes out is decided from the payment itself rather than
     * from a flag passed around: a plan that was pending before this payment is
     * a new subscription, one that was already running is a renewal.
     *
     * Wrapped so a messaging problem can never undo a captured payment.
     */
    private function announce(Payment $payment): void
    {
        $subscription = $payment->subscription?->fresh();

        if (! $subscription) {
            return;
        }

        try {
            $messenger = app(Messenger::class);

            if ($payment->purpose === PaymentPurpose::ClothTopUp) {
                $messenger->notify($subscription, MessagePurpose::ClothTopUp);

                return;
            }

            if ($this->wasFirstPayment) {
                $messenger->notify($subscription, MessagePurpose::SubscriptionStarted);

                $this->emailWelcome($subscription);

                // And the office, so somebody assigns a cleaner. v1 sent this
                // to a number written into the source; this reads a setting.
                if ($office = trim((string) SiteSettings::get('admin_notify_phone'))) {
                    $messenger->notify(
                        $subscription,
                        MessagePurpose::SubscriptionStartedAdmin,
                        toPhone: $office,
                    );
                }

                return;
            }

            $messenger->notify($subscription, MessagePurpose::Renewed);
        } catch (\Throwable $e) {
            Log::error('A payment was captured but the customer could not be told.', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The period this payment should actually move on.
     *
     * Not simply the one the payment points at. A plan is a chain of periods,
     * and paying against a link that has already been superseded used to create
     * a second live period beside the real one - so a car had two plans running,
     * ending on the same day, and would have been billed twice and cleaned once.
     *
     * The car's live period is the only sensible thing to extend, whichever row
     * the office happened to click.
     */
    private function periodToExtend(Payment $payment): ?Subscription
    {
        $subscription = $payment->subscription;

        if (! $subscription || $subscription->status !== SubscriptionStatus::Ended) {
            return $subscription;
        }

        $live = Subscription::query()
            ->where('vehicle_id', $subscription->vehicle_id)
            ->whereNot('id', $subscription->id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Hold, SubscriptionStatus::Pending])
            ->orderByDesc('sequence')
            ->first();

        if (! $live) {
            // Nothing running: this ended period is the plan, and paying for it
            // is a genuine restart.
            return $subscription;
        }

        Log::info('A payment was made against a period that had already been renewed; extending the live one instead.', [
            'payment_id' => $payment->id,
            'paid_against' => $subscription->id,
            'extended' => $live->id,
        ]);

        // Re-pointed so the money hangs off the period it actually paid for.
        $payment->forceFill(['subscription_id' => $live->id])->save();

        return $live;
    }

    /**
     * The next period number for this car, counted across every period it has.
     */
    private function nextSequenceFor(Subscription $subscription): int
    {
        return 1 + (int) Subscription::query()
            ->where('vehicle_id', $subscription->vehicle_id)
            ->max('sequence');
    }

    /**
     * The welcome email, when there is an address to send it to.
     *
     * Optional on purpose. Most customers give a phone number and no email, so
     * the same information is in the WhatsApp welcome as well - this is the
     * fuller version for the ones who did, not the only copy.
     *
     * Failures are swallowed and logged. A mail server being down must not
     * roll back a payment that has already been taken.
     */
    private function emailWelcome(Subscription $subscription): void
    {
        $email = $subscription->customer?->email;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new WelcomeToEswachh($subscription));
        } catch (\Throwable $e) {
            Log::warning('The welcome email could not be sent.', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Was the payment just captured the one that started this plan?
     *
     * Set by extendSubscription, which is the only thing that knows - by the
     * time announce() runs the plan is already active either way.
     */
    private bool $wasFirstPayment = false;

    private function extendSubscription(Payment $payment): void
    {
        $subscription = $this->periodToExtend($payment);

        if (! $subscription) {
            return;
        }

        $this->wasFirstPayment = $subscription->status === SubscriptionStatus::Pending;

        DB::transaction(function () use ($payment, $subscription) {
            $months = $subscription->duration?->months ?? 1;

            // A subscription still in date is extended from its end date, so a
            // customer who renews early keeps the time they paid for. One that
            // has lapsed restarts from today.
            $start = $subscription->period_end?->isFuture()
                ? $subscription->period_end->copy()
                : Carbon::today();

            if ($subscription->status === SubscriptionStatus::Pending) {
                // First payment: this period becomes live rather than a new one
                // being created.
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'period_start' => Carbon::today(),
                    'period_end' => Carbon::today()->addMonths($months),
                    'paid_amount_paise' => $payment->amount_paise,
                ])->save();

                return;
            }

            $next = $subscription->replicate(['held_at', 'ended_at']);
            $next->forceFill([
                /*
                 * Counted from the highest this car has reached, not from the
                 * row in hand.
                 *
                 * Renewing from a superseded period produced a second row with
                 * the same sequence, which is how one car ended up with two
                 * live plans numbered 2 - billed twice and cleaned once.
                 */
                'sequence' => $this->nextSequenceFor($subscription),
                'status' => SubscriptionStatus::Active,
                'period_start' => $start,
                'period_end' => $start->copy()->addMonths($months),
                'amount_paise' => $payment->amount_paise,
                'paid_amount_paise' => $payment->amount_paise,
                'held_at' => null,
                'ended_at' => null,
            ])->save();

            $subscription->forceFill([
                'status' => SubscriptionStatus::Ended,
                'ended_at' => now(),
            ])->save();

            $payment->forceFill(['subscription_id' => $next->id])->save();
        });
    }

    private function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
