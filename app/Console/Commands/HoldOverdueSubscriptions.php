<?php

namespace App\Console\Commands;

use App\Domain\Cloth\ClothLedger;
use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Enums\SubscriptionStatus;
use App\Models\Message;
use App\Models\Subscription;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pauses subscriptions nobody has renewed, after a grace period.
 *
 * This is the job that stops cleaners servicing cars nobody is paying for. It
 * is also the one most capable of doing damage, so it is deliberately cautious:
 *
 *   - a grace period, stated in days and visible in the command signature
 *   - a dry run that shows exactly who would be paused
 *   - a cap, so a bad date or an empty payments table cannot pause the whole
 *     book in one run
 *   - the customer is told, in the same run
 */
class HoldOverdueSubscriptions extends Command
{
    protected $signature = 'eswachh:hold-overdue
                            {--grace= : Days past the renewal date before pausing. Defaults to the setting.}
                            {--limit=50 : Refuse to pause more than this in one run}
                            {--dry-run : Show who would be paused without pausing them}';

    protected $description = 'Put long overdue subscriptions on hold';

    public function handle(Messenger $messenger, ClothLedger $cloths): int
    {
        return SectorContext::withoutScope(function () use ($messenger, $cloths) {
            /*
             * From Settings, which is where it appears to be set.
             *
             * "Days overdue before pausing" has been on that screen since the
             * beginning and was read by nothing at all - the schedule passed
             * --grace=7 and this defaulted to 7, so the box could be changed to
             * any number and every plan still paused after a week. A setting
             * that does nothing is worse than no setting: somebody adjusts it,
             * watches for the change, and concludes the pausing is broken.
             */
            $grace = max(0, (int) ($this->option('grace')
                ?? SiteSettings::get('renewal_grace_days', '7')
                ?: 7));
            $limit = max(1, (int) $this->option('limit'));
            $dryRun = (bool) $this->option('dry-run');

            $cutoff = Carbon::today()->subDays($grace);

            $overdue = Subscription::query()
                ->overdueBeyondGrace($grace)
                ->with('customer', 'vehicle')
                ->orderBy('period_end')
                ->get();

            if ($overdue->isEmpty()) {
                $this->info("Nothing is overdue by more than {$grace} days.");

                return self::SUCCESS;
            }

            $this->info(sprintf(
                '%d subscription(s) overdue since before %s.',
                $overdue->count(),
                $cutoff->format('j M Y'),
            ));

            /*
             * A cap, because this job pauses paying customers' service. If a
             * date goes wrong or a payment import fails, the damage stops at
             * the limit and a person has to look at it - rather than the whole
             * book being paused overnight.
             */
            if ($overdue->count() > $limit && ! $dryRun) {
                $this->error(
                    "That is more than the limit of {$limit}. Nothing has been paused. ".
                    'Check the figures, then re-run with a higher --limit if it is genuinely right.'
                );

                Log::alert('The auto-hold job refused to run: more overdue than the safety limit.', [
                    'overdue' => $overdue->count(),
                    'limit' => $limit,
                ]);

                return self::FAILURE;
            }

            $held = 0;

            foreach ($overdue as $subscription) {
                $days = (int) $subscription->period_end->diffInDays(Carbon::today());

                $this->line(sprintf(
                    '  %s  %s  %d days overdue',
                    str_pad($subscription->vehicle?->registration ?? '—', 12),
                    str_pad($subscription->customer?->name ?? '—', 24),
                    $days,
                ));

                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($subscription, $cloths) {
                    $subscription->forceFill([
                        'status' => SubscriptionStatus::Hold,
                        'held_at' => now(),
                    ])->save();

                    // Cloths do not survive a pause silently. Written off
                    // through the ledger, so the customer can be shown why the
                    // count changed if they come back and renew.
                    $cloths->expire($subscription);
                });

                /*
                 * One message a day about this, whatever it is about.
                 *
                 * Two jobs chase the same customer on the same morning: this
                 * one pauses the plan, and the reminder job asks them to renew.
                 * A plan that lapses today is caught by both - chased at half
                 * past nine while it was still active, paused at ten - so
                 * Neeraj Yadav got "renewal overdue" and then "put on hold"
                 * within half an hour, about the same car.
                 *
                 * The reminder job already skips anybody messaged inside its
                 * gap; this one sent regardless. Checked here too, rather than
                 * relying on the order the schedule happens to run them in,
                 * because the order is not something a person typing the two
                 * commands by hand would know about.
                 */
                if ($this->alreadyToldToday($subscription)) {
                    $this->line('    (already messaged today - not saying it twice)');
                    $held++;

                    continue;
                }

                $messenger->send(
                    $subscription,
                    MessagePurpose::PutOnHold,
                    $this->body($subscription),
                );

                $held++;
            }

            $this->newLine();
            $this->info(($dryRun ? '[dry run] ' : '')."Put {$held} on hold.");

            return self::SUCCESS;
        });
    }

    /**
     * Has this customer already heard about this plan today?
     *
     * Both purposes count. From the customer's side "please renew" and "we have
     * paused it" are the same conversation, and hearing both within half an
     * hour reads as a system talking to itself.
     */
    private function alreadyToldToday(Subscription $subscription): bool
    {
        return Message::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('purpose', [
                MessagePurpose::RenewalOverdue->value,
                MessagePurpose::PutOnHold->value,
            ])
            ->whereDate('sent_on', Carbon::today()->toDateString())
            ->exists();
    }

    private function body(Subscription $subscription): string
    {
        $car = $subscription->vehicle?->registration ?? 'your car';
        $name = $subscription->customer?->name ?? 'there';

        return "Hello {$name}, cleaning for {$car} has been paused because the plan was not renewed. "
            .'Renew any time and we will start again from the next day.';
    }
}
