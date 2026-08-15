<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Models\Subscription;
use App\Support\Tenancy\BranchContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tells customers their subscription is about to run out, and then that it has.
 *
 * Reminders go out on fixed offsets rather than every day. A customer messaged
 * daily for a fortnight stops reading the messages, which is worse than not
 * sending them - so this sends four, at points where each one says something
 * new, and the message table's unique key guarantees no more than that.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'eswachh:send-renewal-reminders
                            {--date= : Pretend today is this date}
                            {--dry-run : List who would be messaged without messaging them}';

    protected $description = 'Message customers whose subscription is due or overdue';

    /**
     * Days from the renewal date on which we say something.
     *
     * Negative is before. The one on the day itself and the one three days
     * after are the two that actually get people to pay.
     */
    private const BEFORE = [-7, -3, 0];

    private const AFTER = [3];

    public function handle(Messenger $messenger): int
    {
        return BranchContext::withoutScope(function () use ($messenger) {
            $today = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
            $dryRun = (bool) $this->option('dry-run');

            if (! $messenger->deliveryEnabled()) {
                // Said out loud rather than discovered later. The run still
                // happens and still records what would have gone out.
                $this->warn('Delivery is off: '.$messenger->suppressionReason().' Messages will be recorded, not sent.');
            }

            $dates = collect(array_merge(self::BEFORE, self::AFTER))
                ->mapWithKeys(fn (int $offset) => [$offset => $today->copy()->subDays($offset)->toDateString()]);

            $sent = 0;
            $skipped = 0;

            foreach ($dates as $offset => $renewalDate) {
                $purpose = $offset > 0 ? MessagePurpose::RenewalOverdue : MessagePurpose::RenewalDue;

                $due = Subscription::query()
                    ->active()
                    ->whereDate('period_end', $renewalDate)
                    ->with('customer', 'vehicle')
                    ->get();

                foreach ($due as $subscription) {
                    if ($dryRun) {
                        $this->line(sprintf(
                            '  would message %s about %s (%s)',
                            $subscription->customer?->name ?? 'unknown',
                            $subscription->vehicle?->registration ?? 'no car',
                            $this->describe($offset),
                        ));
                        $sent++;

                        continue;
                    }

                    $message = $messenger->send(
                        $subscription,
                        $purpose,
                        $this->body($subscription, $offset),
                        on: $today,
                    );

                    // Null means there is already one today, or no phone
                    // number. Neither is a failure.
                    $message ? $sent++ : $skipped++;
                }
            }

            $this->newLine();
            $this->info(($dryRun ? '[dry run] ' : '')."Messaged {$sent}, skipped {$skipped}.");

            return self::SUCCESS;
        });
    }

    private function body(Subscription $subscription, int $offset): string
    {
        $car = $subscription->vehicle?->registration ?? 'your car';
        $name = $subscription->customer?->name ?? 'there';
        $amount = number_format($subscription->amount(), 0);

        return match (true) {
            $offset < 0 => "Hello {$name}, the cleaning plan for {$car} is due for renewal on "
                .$subscription->period_end->format('j M').". Renew for Rs {$amount} to keep the service running.",

            $offset === 0 => "Hello {$name}, the cleaning plan for {$car} is due today. "
                ."Renew for Rs {$amount} to avoid any break in service.",

            default => "Hello {$name}, the cleaning plan for {$car} is now overdue. "
                ."Please renew for Rs {$amount} - the service will be paused if we do not hear from you.",
        };
    }

    private function describe(int $offset): string
    {
        return match (true) {
            $offset < 0 => abs($offset).' days before',
            $offset === 0 => 'due today',
            default => $offset.' days overdue',
        };
    }
}
