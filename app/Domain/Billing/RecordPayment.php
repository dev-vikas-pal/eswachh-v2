<?php

namespace App\Domain\Billing;

use App\Domain\Cloth\ClothLedger;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ClothBundle;
use App\Models\Payment;
use App\Support\Tenancy\BranchContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        return BranchContext::withoutScope(fn () => $this->capturePayment($callback, $details));
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
        if ($payment->status !== PaymentStatus::Captured
            || $payment->purpose !== PaymentPurpose::Subscription) {
            return;
        }

        try {
            // Same reasoning as complete(): a console command has no user, and
            // the payment's own branch is the authority.
            BranchContext::withoutScope(fn () => $this->extendSubscription($payment));
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
                'invoice_number' => $payment->invoice_number ?? InvoiceNumber::next($payment->branch_id),
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
    private function extendSubscription(Payment $payment): void
    {
        $subscription = $payment->subscription;

        if (! $subscription) {
            return;
        }

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
                'sequence' => $subscription->sequence + 1,
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
