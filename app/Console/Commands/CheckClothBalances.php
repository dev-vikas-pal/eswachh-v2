<?php

namespace App\Console\Commands;

use App\Domain\Cloth\ClothLedger;
use App\Models\ClothEntry;
use App\Models\Subscription;
use App\Support\Tenancy\SectorContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks every cached cloth balance against its ledger.
 *
 * The balance on a subscription is a convenience; the entries are the truth. If
 * the two ever disagree, something wrote the column outside ClothLedger, and
 * that is worth knowing on the day it happens rather than when a customer
 * argues about it months later.
 *
 * --repair rebuilds the cached column from the ledger. That is a projection
 * being recomputed, not history being edited - the ledger was never wrong.
 */
class CheckClothBalances extends Command
{
    protected $signature = 'eswachh:check-cloth-balances
                            {--repair : Rebuild the cached balance from the ledger}';

    protected $description = 'Verify cached cloth balances against the ledger';

    public function handle(): int
    {
        return SectorContext::withoutScope(function () {
            $repair = (bool) $this->option('repair');

            // The ledger total per subscription, in one query rather than one
            // per subscription.
            $ledgerTotals = ClothEntry::query()
                ->select('subscription_id', DB::raw('SUM(quantity) AS total'))
                ->groupBy('subscription_id')
                ->pluck('total', 'subscription_id');

            $subscriptions = Subscription::query()
                ->where(fn ($q) => $q->where('cloth_service', true)->orWhere('cloth_balance', '!=', 0))
                ->get();

            $checked = 0;
            $wrong = [];

            foreach ($subscriptions as $subscription) {
                $checked++;

                $expected = (int) ($ledgerTotals[$subscription->id] ?? 0);
                $cached = (int) $subscription->cloth_balance;

                if ($expected === $cached) {
                    continue;
                }

                $wrong[] = [
                    substr($subscription->id, 0, 8).'…',
                    $cached,
                    $expected,
                    $cached - $expected,
                ];

                Log::warning('A cloth balance does not match its ledger.', [
                    'subscription_id' => $subscription->id,
                    'cached' => $cached,
                    'ledger' => $expected,
                ]);

                if ($repair) {
                    /*
                     * Rebuild the cached column from the ledger. This is not
                     * editing history - the ledger was never wrong, only the
                     * projection of it was, so the fix is to recompute the
                     * projection rather than to post an entry that would bend
                     * the ledger towards a number we already know is bad.
                     */
                    $subscription->forceFill(['cloth_balance' => $expected])->saveQuietly();
                }
            }

            $this->info("Checked {$checked} subscription(s) with a cloth balance.");

            if (empty($wrong)) {
                $this->info('Every balance matches its ledger.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->table(['Subscription', 'Balance', 'Ledger', 'Out by'], $wrong);

            if ($repair) {
                $this->info('Rebuilt from the ledger.');

                return self::SUCCESS;
            }

            $this->warn('Run again with --repair to rebuild them from the ledger.');

            // A non-zero exit so a scheduled run is noticed rather than
            // scrolling past in a log.
            return self::FAILURE;
        });
    }
}
