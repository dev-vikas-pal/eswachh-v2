<?php

namespace App\Console\Commands;

use App\Domain\Billing\RazorpayGateway;
use App\Domain\Billing\RecordPayment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Tenancy\SectorContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Finds money the callback path missed.
 *
 * A customer who pays and then closes the tab before being redirected leaves a
 * charge at Razorpay and an "initiated" row here. Nothing else will ever
 * reconcile the two: the browser is gone. This runs nightly, asks the gateway
 * what really happened to every open attempt, and settles it.
 *
 * v1 had no equivalent, which is why customers who had paid were still being
 * chased for renewal.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'eswachh:reconcile-payments
                            {--days=7 : How far back to look}
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Settle payment attempts that never came back from the gateway';

    public function handle(RazorpayGateway $gateway, RecordPayment $recorder): int
    {
        // Reconciliation is a system job and has to see every branch, so it
        // runs outside the tenancy scope. Explicit, not accidental.
        return SectorContext::withoutScope(function () use ($gateway, $recorder) {
            $since = Carbon::now()->subDays((int) $this->option('days'));
            $dryRun = (bool) $this->option('dry-run');

            $open = Payment::query()
                ->where('status', PaymentStatus::Initiated)
                ->whereNotNull('gateway_order_id')
                // Give an in-flight checkout time to finish on its own before
                // interfering with it.
                ->where('created_at', '<', Carbon::now()->subMinutes(15))
                ->where('created_at', '>=', $since)
                ->get();

            if ($open->isEmpty()) {
                $this->info('No open payment attempts to settle.');

                return self::SUCCESS;
            }

            $this->info("Checking {$open->count()} open attempt(s) against the gateway.");

            $settled = 0;
            $abandoned = 0;
            $unknown = 0;

            foreach ($open as $payment) {
                $charges = $gateway->paymentsForOrder((string) $payment->gateway_order_id);

                // The gateway did not answer. Nothing is known, so nothing is
                // changed: tomorrow's run asks again.
                if ($charges === null) {
                    $unknown++;
                    $this->line("  unknown    {$payment->id}  the gateway did not answer; left open");

                    continue;
                }

                $found = $this->findGatewayPayment($payment, $charges);

                // Money is there but not something we can bank - a charge still
                // only authorised, or one for a different amount. Marking it
                // failed would hide a customer who paid; it is left open and
                // logged for a person to look at.
                if ($found === null && $this->hasLiveCharge($charges)) {
                    $unknown++;
                    $this->line("  CHECK      {$payment->id}  a charge exists but does not settle this order; left open");

                    continue;
                }

                if (! $found) {
                    // No charge at the gateway means the customer never paid.
                    // Marked failed rather than deleted, so the abandonment
                    // rate stays measurable.
                    $abandoned++;

                    if (! $dryRun) {
                        $payment->forceFill([
                            'status' => PaymentStatus::Failed,
                            'notes' => 'No matching charge at the gateway when reconciled on '.now()->toDateString().'.',
                        ])->save();
                    }

                    $this->line("  abandoned  {$payment->id}  ₹".number_format($payment->amount(), 2));

                    continue;
                }

                $this->line("  PAID       {$payment->id}  ₹".number_format($payment->amount(), 2)."  {$found['id']}");

                if ($dryRun) {
                    $settled++;

                    continue;
                }

                /*
                 * Reconstructed rather than replayed: there is no signature to
                 * check because there was no callback. The gateway's own API
                 * answering over an authenticated connection is the proof
                 * here.
                 *
                 * Through the same lock as the callback, so a browser that
                 * returns while this runs cannot renew the plan a second time.
                 */
                $claimed = $recorder->settleFromReconciliation(
                    $payment,
                    $found,
                    'Settled by reconciliation; the customer never returned from the gateway.',
                );

                if (! $claimed) {
                    $this->line('             already settled by its callback; nothing done');

                    continue;
                }

                $settled++;

                Log::info('Reconciliation settled a payment the callback never delivered.', [
                    'payment_id' => $payment->id,
                    'gateway_payment_id' => $found['id'],
                    'amount_paise' => $payment->amount_paise,
                ]);
            }

            $this->newLine();
            $this->info(($dryRun ? '[dry run] ' : '')."Settled {$settled}, marked {$abandoned} abandoned, left {$unknown} open.");

            return self::SUCCESS;
        });
    }

    /**
     * The charge that settles this order, from what the gateway holds for it.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<string, mixed>|null
     */
    private function findGatewayPayment(Payment $payment, array $payments): ?array
    {
        foreach ($payments as $candidate) {
            if (($candidate['status'] ?? null) !== 'captured') {
                continue;
            }

            // Only accept a charge for the amount we asked for. A mismatch is
            // a problem for a person, not something to quietly bank.
            if ((int) ($candidate['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::warning('A gateway charge did not match the amount we requested.', [
                    'payment_id' => $payment->id,
                    'expected_paise' => $payment->amount_paise,
                    'gateway_paise' => $candidate['amount'] ?? null,
                ]);

                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Has the customer handed over money on this order in any form?
     *
     * A failed attempt is not money. Anything else - authorised, captured at a
     * different amount, refunded - is something a person should see before
     * the order is called abandoned.
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function hasLiveCharge(array $payments): bool
    {
        foreach ($payments as $candidate) {
            if (($candidate['status'] ?? null) !== 'failed') {
                return true;
            }
        }

        return false;
    }
}
