<?php

namespace App\Console\Commands;

use App\Models\ServiceLog;
use App\Support\Tenancy\SectorContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes daily cleaning records once they are past their retention period.
 *
 * The requirements document sets this at fifty days: daily cleaning data is
 * kept so a dispute about last week can be settled, and after that it is a
 * growing table nobody reads. v1 never built it, so its service history has
 * been accumulating since the day it went live.
 *
 * What is deleted is deliberately narrow - the per-day, per-car service log and
 * nothing else. Payments, plans, complaints and cloth movements are records of
 * money or of promises and are kept. Attendance is kept too: it is a record of
 * who worked, which is a payroll question rather than an operational one.
 */
class PruneServiceHistory extends Command
{
    protected $signature = 'eswachh:prune-service-history
                            {--days= : Keep this many days instead of the configured number}
                            {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Delete daily cleaning records older than the retention period';

    /** What the requirements document asks for. */
    private const DEFAULT_DAYS = 50;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('eswachh.service_log_retention_days', self::DEFAULT_DAYS));

        if ($days < 7) {
            // A guard against a typo emptying the table. Somebody who genuinely
            // wants a shorter window can change the config.
            $this->error("Refusing to keep only {$days} day(s). Seven is the shortest this will do.");

            return self::FAILURE;
        }

        $cutoff = Carbon::today()->subDays($days);

        return SectorContext::withoutScope(function () use ($cutoff, $days) {
            $doomed = ServiceLog::query()->whereDate('serviced_on', '<', $cutoff);

            $count = (clone $doomed)->count();

            if ($count === 0) {
                $this->info("Nothing older than {$days} days. Kept everything from {$cutoff->toDateString()}.");

                return self::SUCCESS;
            }

            if ($this->option('dry-run')) {
                $this->warn("Dry run: {$count} record(s) before {$cutoff->toDateString()} would be deleted.");

                return self::SUCCESS;
            }

            /*
             * Deleted in batches. A single statement over a year of rows locks
             * the table for long enough that the cleaners' app times out, and
             * this runs on a schedule while somebody may well be using it.
             */
            $deleted = 0;

            do {
                $batch = (clone $doomed)->limit(1000)->forceDelete();
                $deleted += $batch;
            } while ($batch > 0);

            $this->info("Deleted {$deleted} cleaning record(s) from before {$cutoff->toDateString()}.");

            return self::SUCCESS;
        });
    }
}
