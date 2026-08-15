<?php

namespace App\Domain\Cloth;

use App\Enums\ClothEntryType;
use App\Models\ClothBundle;
use App\Models\ClothEntry;
use App\Models\Payment;
use App\Models\ServiceLog;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only way a cloth balance ever changes.
 *
 * Nothing else writes subscriptions.cloth_balance. Every method here appends a
 * ledger entry and updates the cached balance inside one transaction, under a
 * row lock, so the two cannot drift apart and a concurrent request cannot read
 * a stale balance and write it back.
 *
 * v1 had no ledger and let anything set the number, which is why all 22 of its
 * cloth top-up payments left the balance at zero.
 */
class ClothLedger
{
    /**
     * A bundle bought.
     */
    public function purchase(
        Subscription $subscription,
        ClothBundle $bundle,
        ?Payment $payment = null,
        ?User $actor = null,
    ): ClothEntry {
        return $this->post(
            $subscription,
            ClothEntryType::Purchase,
            $bundle->cloth_count,
            [
                'payment_id' => $payment?->id,
                'cloth_bundle_id' => $bundle->id,
                'actor_id' => $actor?->id,
            ],
        );
    }

    /**
     * One cloth used on one clean.
     *
     * Refuses at zero rather than going negative. A negative balance is not a
     * thing that can happen in the world, so it must not be a thing that can
     * happen in the database.
     */
    public function issue(Subscription $subscription, ServiceLog $log, ?User $actor = null): ?ClothEntry
    {
        if (! $subscription->cloth_service) {
            return null;
        }

        if ($subscription->cloth_balance <= 0) {
            Log::info('A car was cleaned with no cloths left on the subscription.', [
                'subscription_id' => $subscription->id,
                'service_log_id' => $log->id,
            ]);

            // The car was still cleaned. Refusing the clean because the cloth
            // count ran out would be the wrong way round - this is a billing
            // problem, and it surfaces on the low balance report.
            return null;
        }

        try {
            return $this->post(
                $subscription,
                ClothEntryType::Issue,
                -1,
                ['service_log_id' => $log->id, 'actor_id' => $actor?->id],
            );
        } catch (QueryException $e) {
            // The unique key on service_log_id fired: this clean has already
            // taken its cloth. A retried request must not charge twice.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ClothEntry::query()->where('service_log_id', $log->id)->first();
            }

            throw $e;
        }
    }

    /**
     * A correction made by a person.
     *
     * The reason is required, not optional. A balance changed by hand with no
     * explanation is the exact thing this ledger exists to prevent.
     */
    public function adjust(
        Subscription $subscription,
        int $quantity,
        string $reason,
        ?User $actor = null,
    ): ClothEntry {
        if ($quantity === 0) {
            throw new LogicException('An adjustment of zero changes nothing.');
        }

        if (trim($reason) === '') {
            throw new LogicException('An adjustment needs a reason.');
        }

        return $this->post(
            $subscription,
            ClothEntryType::Adjustment,
            $quantity,
            ['reason' => $reason, 'actor_id' => $actor?->id],
        );
    }

    /**
     * Write off what is left when a subscription ends.
     */
    public function expire(Subscription $subscription, ?User $actor = null): ?ClothEntry
    {
        if ($subscription->cloth_balance <= 0) {
            return null;
        }

        return $this->post(
            $subscription,
            ClothEntryType::Expiry,
            -$subscription->cloth_balance,
            [
                'reason' => 'Subscription ended with cloths unused.',
                'actor_id' => $actor?->id,
            ],
        );
    }

    /**
     * What the ledger says the balance should be.
     *
     * Summed from the entries, ignoring the cached column entirely, so it can
     * be compared against it.
     */
    public function balanceFromLedger(Subscription $subscription): int
    {
        return (int) ClothEntry::query()
            ->where('subscription_id', $subscription->id)
            ->sum('quantity');
    }

    // ---------------------------------------------------------------- private

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function post(
        Subscription $subscription,
        ClothEntryType $type,
        int $quantity,
        array $attributes = [],
    ): ClothEntry {
        return DB::transaction(function () use ($subscription, $type, $quantity, $attributes) {
            /*
             * Lock the subscription before reading the balance. Without this,
             * two cleans recorded at the same moment both read the same
             * balance and one of the decrements is lost - the classic way a
             * counter under-counts under load.
             */
            $locked = Subscription::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            /*
             * The new balance is built from the ledger, never from the cached
             * column. That is what makes the column a projection rather than a
             * second source of truth: if it were ever wrong, deriving from it
             * would fold the error permanently into the history, and the check
             * command would have nothing left to compare against.
             */
            $balanceAfter = $this->balanceFromLedger($locked) + $quantity;

            if ($balanceAfter < 0) {
                throw new LogicException('That would leave a negative cloth balance.');
            }

            $entry = ClothEntry::create(array_merge([
                'branch_id' => $locked->branch_id,
                'subscription_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $balanceAfter,
            ], $attributes));

            $locked->forceFill([
                'cloth_balance' => $balanceAfter,
                // Buying a bundle turns the service on. v1 took the money and
                // left the flag off on every single top-up it ever sold.
                'cloth_service' => $locked->cloth_service || $type->addsStock(),
            ])->save();

            // Keep the caller's copy in step, so it does not go on to write a
            // stale balance back.
            $subscription->setAttribute('cloth_balance', $balanceAfter);
            $subscription->setAttribute('cloth_service', $locked->cloth_service);

            return $entry;
        });
    }
}
