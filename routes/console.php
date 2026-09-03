<?php

use App\Console\Commands\SendDailySummary;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Declared here rather than in a Kernel, which is how Laravel 12 does it.
|
| Every job below is guarded three ways, because an unattended job that runs
| twice is worse than one that does not run at all:
|
|   withoutOverlapping  a slow run never gets a second copy on top of it
|   onOneServer         adding a second web server does not double the work
|   runInBackground     one slow job does not delay the rest of the minute
|
*/

/*
 * Settles payments the customer paid for but never came back from.
 *
 * Nightly and just after midnight, when the gateway is quiet. Nothing else
 * will ever reconcile these: the browser that would have delivered the
 * callback is long closed.
 */
Schedule::command('eswachh:reconcile-payments --days=7')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * The day's round, told once, at an hour somebody is awake.
 *
 * Every outcome the cleaner records used to message the customer the moment
 * they tapped it - six in the morning on an early round, and twice over for a
 * household with two cars. Nothing in it is urgent, so it waits.
 *
 * Runs hourly and does its own work only in the configured hour, because the
 * hour is a setting the business can move without a release, and a schedule
 * fixed at boot could not follow it.
 */
Schedule::command('eswachh:send-daily-summary')
    ->hourly()
    ->when(fn () => (int) now()->format('G') === SendDailySummary::hour())
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Pause what nobody has renewed, a week past the date.
 *
 * Before the reminders, deliberately, and this order matters.
 *
 * A plan that reaches the end of its grace period today is caught by both
 * jobs. Chasing first meant the customer was asked to renew at half past nine,
 * while the plan was still active, and told it had been paused at ten - two
 * messages about the same car, half an hour apart, contradicting each other on
 * whether the cleaning was still happening.
 *
 * Pausing first settles what state each plan is in before anybody is written
 * to, so the reminder that follows says one true thing. Neither job will now
 * message a customer who has already heard today, whichever way round they are
 * run, but the schedule should still put them in the order that makes sense.
 *
 * After reconciliation either way: a customer who paid last night and never
 * got redirected back has been settled by then, so they are neither chased nor
 * paused over money they have already handed over. That was a real complaint
 * in v1.
 */
// No --grace here on purpose: the command reads "Days overdue before pausing"
// from Settings, and passing it explicitly would override the office's choice.
Schedule::command('eswachh:hold-overdue')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Then chase everyone who is overdue or already paused, in whatever state the
 * job above has just left them in.
 */
Schedule::command('eswachh:send-renewal-reminders')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Weekly, and quiet unless something is wrong. Exits non-zero on a mismatch so
 * a failed run is noticed rather than scrolling past in a log.
 */
Schedule::command('eswachh:check-cloth-balances')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The database, copied and checked, before anything else touches it.
 *
 * Earliest of the lot on purpose: if a later job goes wrong, the copy taken at
 * ten past midnight is from before it did. A backup taken after the night's
 * work has already run is a backup of the damage.
 *
 * A failure raises a critical alert rather than only writing to a log, because
 * a backup quietly failing for three months is how a restore turns out to be
 * impossible on the day it is needed.
 */
Schedule::command('eswachh:backup --keep=14')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Fifty days of daily cleaning records, as the requirements document asks for.
 *
 * Runs after the backup, deliberately: the night's backup therefore always
 * contains the data that is about to be deleted, so a retention rule can never
 * be the reason something is unrecoverable.
 */
Schedule::command('eswachh:prune-service-history')
    ->dailyAt('00:40')
    ->withoutOverlapping()
    ->onOneServer();
