<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Models\ClothMovement;
use App\Models\ServiceLog;
use App\Models\Subscription;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One message a day, saying what happened to each of a customer's cars.
 *
 * This replaces four messages that fired the moment a cleaner tapped a button:
 * cleaned, could-not-clean, cloths collected, cloths returned. On an early
 * round that is six in the morning, and a household with two cars was woken
 * twice - which is how a service people are happy with becomes a service people
 * mute.
 *
 * Nothing is lost by waiting. None of it is urgent: the customer cannot act on
 * "your car was cleaned", and the one thing they might act on - "we could not
 * reach it" - is better read in the evening when they can move the car for
 * tomorrow than at dawn when they cannot.
 *
 * Anything a customer is actually waiting for still goes immediately: the
 * welcome, the receipt, who their cleaner is, and anything about a complaint
 * they raised.
 */
class SendDailySummary extends Command
{
    protected $signature = 'eswachh:send-daily-summary
                            {--date= : The day to report on, defaults to today}';

    protected $description = "Tell each customer what happened to their cars today";

    public function handle(Messenger $messenger): int
    {
        $on = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();

        /*
         * Scopes off: this runs from the scheduler with nobody signed in, so
         * the fail-closed scope would otherwise find no customers at all and
         * the job would report a cheerful zero every evening.
         */
        return SectorContext::withoutScope(function () use ($messenger, $on) {
            $logs = ServiceLog::query()
                ->with(['vehicle:id,registration,customer_id', 'subscription'])
                ->whereDate('serviced_on', $on)
                ->get()
                ->groupBy(fn (ServiceLog $log) => $log->vehicle?->customer_id);

            $cloths = ClothMovement::query()
                ->with(['vehicle:id,registration,customer_id'])
                ->whereDate('moved_on', $on)
                ->get()
                ->groupBy(fn (ClothMovement $m) => $m->vehicle?->customer_id);

            $customerIds = $logs->keys()->merge($cloths->keys())->filter()->unique();

            if ($customerIds->isEmpty()) {
                $this->info('Nothing happened today. Nothing to send.');

                return self::SUCCESS;
            }

            $sent = 0;

            foreach ($customerIds as $customerId) {
                $lines = $this->linesFor($logs->get($customerId), $cloths->get($customerId));

                if ($lines === []) {
                    continue;
                }

                /*
                 * Any of their plans will do as the carrier: the message is
                 * about the customer, not one subscription, and the row has to
                 * hang off something. Their first is as good as any.
                 */
                $plan = Subscription::query()
                    ->where('customer_id', $customerId)
                    ->orderByDesc('created_at')
                    ->first();

                if (! $plan) {
                    continue;
                }

                $message = $messenger->notify($plan, MessagePurpose::DailySummary, [
                    'round' => implode("\n", $lines),
                ]);

                if ($message) {
                    $sent++;
                }
            }

            $this->info("Told {$sent} customer(s) about ".$on->toDateString().'.');

            return self::SUCCESS;
        });
    }

    /**
     * One readable line per car.
     *
     * @param  \Illuminate\Support\Collection<int, ServiceLog>|null  $logs
     * @param  \Illuminate\Support\Collection<int, ClothMovement>|null  $movements
     * @return array<int, string>
     */
    private function linesFor($logs, $movements): array
    {
        $lines = [];

        foreach ($logs ?? [] as $log) {
            $car = $log->vehicle?->registration ?? 'your car';

            $lines[] = $log->outcome->wasCleaned()
                ? "{$car}: cleaned."
                : "{$car}: not cleaned - ".($log->outcome->customerExplanation() ?? 'we could not do it today').'.';
        }

        foreach ($movements ?? [] as $movement) {
            $car = $movement->vehicle?->registration ?? 'your car';

            $lines[] = $movement->direction === ClothMovement::PICKUP
                ? "{$car}: {$movement->cloth_count} cloth(s) collected for ironing."
                : "{$car}: {$movement->cloth_count} cloth(s) returned.";
        }

        return $lines;
    }

    /**
     * The hour the business wants this sent, for the scheduler.
     *
     * Read from settings so it can be moved without a release - the right hour
     * is a thing somebody discovers by watching customers, not by deciding.
     */
    public static function hour(): int
    {
        return (int) (SiteSettings::get('daily_summary_hour') ?? 19);
    }
}
