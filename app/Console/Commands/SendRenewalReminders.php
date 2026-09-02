<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Enums\SubscriptionStatus;
use App\Models\Message;
use App\Models\Subscription;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Chases every customer whose plan has run out, and everyone already on hold.
 *
 * Daily, both of them, until the customer renews - which is how the business
 * has always run it. What changes as a plan moves from expired to on hold is
 * what the message says, not how often it arrives: one asks them to renew
 * before the cleaning stops, the other tells them it already has.
 *
 * The state is what decides, not an anniversary. It used to work on fixed
 * offsets - chased if it had expired exactly one, three or seven days ago, and
 * otherwise never - which on a freshly imported database reaches almost nobody:
 * of eleven overdue plans, six sat on an offset and the other five, at six,
 * eight, nine and sixty-three days past their date, had already missed every
 * chance they were going to get. The office sees eleven overdue on the
 * dashboard and six messages, and reasonably concludes the job is broken.
 *
 * The gap is measured from the last message actually sent about it, so it is
 * self-correcting: one sent by hand today postpones tomorrow's automatic one.
 *
 * Nobody is chased before their date. That is a business rule, and it is also
 * what the provider will carry - the approved WhatsApp templates cover
 * "expired" and "on hold" and nothing else, so a reminder sent early has no
 * template to travel in.
 *
 * Nothing here stops on its own. A plan on hold that nobody marks Ended is
 * chased every day indefinitely, which is the same as the manual process it
 * replaces except that a person used to get bored. Marking it Ended is what
 * stops it.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'eswachh:send-renewal-reminders
                            {--date= : Pretend today is this date}
                            {--overdue-every= : Days between reminders while a plan is overdue. Defaults to the setting.}
                            {--hold-every= : Days between reminders while a plan is on hold. Defaults to the setting.}
                            {--dry-run : List who would be messaged without messaging them}';

    protected $description = 'Message customers whose subscription has expired or is on hold';

    public function handle(Messenger $messenger): int
    {
        return SectorContext::withoutScope(function () use ($messenger) {
            $today = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
            $dryRun = (bool) $this->option('dry-run');

            /*
             * Both daily by default, and both from Settings.
             *
             * Two numbers rather than one so the halves can be tuned apart:
             * chasing a plan that is still being cleaned is a different
             * conversation from chasing one where the cleaning has stopped, and
             * the office may decide the first can be gentler without touching
             * the second. The options exist for testing and one-off runs.
             */
            $overdueGap = $this->gap('overdue-every', 'reminder_gap_overdue_days', 1);
            $holdGap = $this->gap('hold-every', 'reminder_gap_hold_days', 1);

            if (! $messenger->deliveryEnabled()) {
                // Said out loud rather than discovered later. The run still
                // happens and still records what would have gone out.
                $this->warn('Delivery is off: '.$messenger->suppressionReason().' Messages will be recorded, not sent.');
            }

            /*
             * Two audiences, each with its own idea of "recently".
             *
             * Written as one query with two arms rather than two queries, so a
             * plan cannot be picked up by both and messaged twice in a run -
             * which is exactly what would happen to a plan that changed status
             * between them.
             *
             * Hold is matched on the status alone, deliberately. The obvious
             * alternative - counting days from `held_at` - reaches none of the
             * plans that came across from v1, because the importer never set
             * that column and all thirteen of them hold null.
             */
            $due = Subscription::query()
                ->where(fn ($q) => $q
                    ->where(fn ($active) => $active
                        ->where('status', SubscriptionStatus::Active)
                        ->whereDate('period_end', '<', $today->toDateString())
                        ->whereNotIn('id', $this->chasedSince($today, $overdueGap)))
                    ->orWhere(fn ($held) => $held
                        ->where('status', SubscriptionStatus::Hold)
                        ->whereNotIn('id', $this->chasedSince($today, $holdGap))))
                ->with('customer', 'vehicle')
                ->orderBy('period_end')
                ->get();

            $sent = 0;
            $skipped = 0;

            foreach ($due as $subscription) {
                $purpose = $subscription->status === SubscriptionStatus::Hold
                    ? MessagePurpose::PutOnHold
                    : MessagePurpose::RenewalOverdue;

                if ($dryRun) {
                    $this->line(sprintf(
                        '  would message %s about %s (%s)',
                        $subscription->customer?->name ?? 'unknown',
                        $subscription->vehicle?->registration ?? 'no car',
                        $this->describe($subscription, $today),
                    ));
                    $sent++;

                    continue;
                }

                $message = $messenger->send(
                    $subscription,
                    $purpose,
                    $this->body($subscription),
                    on: $today,
                );

                // Null means there is already one today, or no phone number.
                // Neither is a failure.
                $message ? $sent++ : $skipped++;
            }

            $this->newLine();
            $this->info(($dryRun ? '[dry run] ' : '')."Messaged {$sent}, skipped {$skipped}.");

            return self::SUCCESS;
        });
    }

    /**
     * How many days to leave between messages, from the option or the setting.
     */
    private function gap(string $option, string $setting, int $fallback): int
    {
        $chosen = $this->option($option) ?? SiteSettings::get($setting, (string) $fallback);

        // A blank setting means "use the default", not "message them every
        // hour" - an office that clears a box is not asking for that.
        return max(1, (int) ($chosen ?: $fallback));
    }

    /**
     * Plans already chased inside the window.
     *
     * A subquery rather than a list of ids: on a database with years of
     * messages behind it, pulling every recent subscription id into PHP to feed
     * back into a WHERE IN is the sort of thing that works perfectly until it
     * does not.
     *
     * Measured from the last message actually sent, so it is self-correcting -
     * a plan chased today is left alone for its gap whether that message went
     * out on schedule, by hand, or in a catch-up run after an import.
     */
    private function chasedSince(Carbon $today, int $gap): Builder
    {
        return Message::query()
            ->whereIn('purpose', [
                MessagePurpose::RenewalOverdue->value,
                MessagePurpose::PutOnHold->value,
            ])
            ->whereDate('sent_on', '>', $today->copy()->subDays($gap)->toDateString())
            ->select('subscription_id');
    }

    /**
     * What the record says, which is not quite what the provider sends.
     *
     * The approved templates carry one variable each - the date - and their
     * wording is fixed by Meta. This is the readable version kept on the
     * message row, so the Messages screen shows something a person can read
     * rather than a template name and a date.
     */
    private function body(Subscription $subscription): string
    {
        $car = $subscription->vehicle?->registration ?? 'your car';
        $name = $subscription->customer?->name ?? 'there';
        $amount = number_format($subscription->amount(), 0);
        $due = $subscription->period_end?->format('j M') ?? '';

        if ($subscription->status === SubscriptionStatus::Hold) {
            return "Hello {$name}, cleaning for {$car} is on hold - the plan was due on {$due}. "
                ."Renew for Rs {$amount} and we will start again from the next round.";
        }

        return "Hello {$name}, the cleaning plan for {$car} expired on {$due}. "
            ."Please renew for Rs {$amount} - the service will be paused if we do not hear from you.";
    }

    private function describe(Subscription $subscription, Carbon $today): string
    {
        if ($subscription->status === SubscriptionStatus::Hold) {
            return 'on hold';
        }

        $days = (int) $today->diffInDays($subscription->period_end, absolute: true);

        return $days === 1 ? 'a day overdue' : "{$days} days overdue";
    }
}
