<?php

namespace App\Domain\Operations;

use App\Domain\Cloth\ClothLedger;
use App\Enums\AttendanceStatus;
use App\Enums\ServiceOutcome;
use App\Enums\SubscriptionStatus;
use App\Models\Attendance;
use App\Models\ServiceLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A cleaner's day: who they are due to visit, and what happened at each car.
 *
 * The round is derived from live subscriptions rather than stored, so a car
 * that goes on hold drops off the list the same day instead of staying on a
 * stale rota. v1 had no rota at all - a cleaner was told their sector and the
 * office typed a number in at the end of the day.
 */
class DailyRound
{
    /**
     * The cars this cleaner is due to visit.
     *
     * Only vehicles whose subscription is live: a car on hold or ended is not
     * work, and putting it on the list means it gets marked missed every day.
     *
     * @return Collection<int, Vehicle>
     */
    public function due(User $cleaner, ?Carbon $on = null): Collection
    {
        $on ??= Carbon::today();

        return Vehicle::query()
            ->where('assigned_cleaner_id', $cleaner->id)
            ->where('status', true)
            ->whereHas('subscriptions', fn ($q) => $q
                ->where('status', SubscriptionStatus::Active)
                ->whereDate('period_end', '>=', $on)
            )
            ->with(['customer:id,name,phone,house_no,address,preferred_time', 'currentSubscription'])
            ->get();
    }

    /**
     * Record what happened at one car.
     *
     * Idempotent by car and day: marking the same car twice corrects the first
     * entry rather than inflating the count. The unique key backs this up, so a
     * double tap on a phone with a slow connection cannot double count.
     */
    public function record(
        Vehicle $vehicle,
        User $cleaner,
        ServiceOutcome $outcome,
        ?Carbon $on = null,
        ?string $note = null,
    ): ServiceLog {
        $on ??= Carbon::today();

        return DB::transaction(function () use ($vehicle, $cleaner, $outcome, $on, $note) {
            $log = ServiceLog::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereDate('serviced_on', $on)
                ->first();

            $attributes = [
                'branch_id' => $vehicle->branch_id,
                'vehicle_id' => $vehicle->id,
                'subscription_id' => $vehicle->currentSubscription?->id,
                'cleaner_id' => $cleaner->id,
                'serviced_on' => $on,
                'serviced_at' => now(),
                'outcome' => $outcome,
                'note' => $note,
            ];

            if ($log) {
                $log->forceFill($attributes)->save();
            } else {
                $log = ServiceLog::create($attributes);
            }

            /*
             * A clean uses a cloth, if the subscription has the service. Done
             * here rather than left to the caller so it cannot be forgotten,
             * and the ledger's unique key on service_log_id means correcting
             * an outcome later does not take a second cloth.
             */
            if ($outcome->wasCleaned() && $vehicle->currentSubscription) {
                app(ClothLedger::class)->issue($vehicle->currentSubscription, $log, $cleaner);
            }

            return $log;
        });
    }

    /**
     * Mark a cleaner's day.
     *
     * One entry per person per day, corrected in place. marked_at is the moment
     * somebody wrote it down, which is not the day being described - a week
     * filled in on a Friday is worth being able to see.
     */
    public function markAttendance(
        User $cleaner,
        AttendanceStatus $status,
        ?Carbon $on = null,
        ?User $markedBy = null,
        ?string $note = null,
    ): Attendance {
        $on ??= Carbon::today();

        return DB::transaction(function () use ($cleaner, $status, $on, $markedBy, $note) {
            $attendance = Attendance::query()
                ->where('cleaner_id', $cleaner->id)
                ->whereDate('worked_on', $on)
                ->first();

            $attributes = [
                'branch_id' => $cleaner->branch_id,
                'cleaner_id' => $cleaner->id,
                'worked_on' => $on,
                'status' => $status,
                'marked_by' => $markedBy?->id,
                'marked_at' => now(),
                'note' => $note,
            ];

            if ($attendance) {
                $attendance->forceFill($attributes)->save();

                return $attendance;
            }

            return Attendance::create($attributes);
        });
    }

    /**
     * How the day went for one cleaner.
     *
     * Every number here is counted from service logs. Nothing is typed in, so
     * nothing can be inflated.
     *
     * @return array<string, mixed>
     */
    public function summary(User $cleaner, ?Carbon $on = null): array
    {
        $on ??= Carbon::today();

        $due = $this->due($cleaner, $on)->count();

        $logs = ServiceLog::query()
            ->where('cleaner_id', $cleaner->id)
            ->whereDate('serviced_on', $on)
            ->get();

        $cleaned = $logs->filter(fn (ServiceLog $l) => $l->outcome->wasCleaned())->count();

        return [
            'date' => $on->toDateString(),
            'cleaner' => ['id' => $cleaner->id, 'name' => $cleaner->name],
            'due' => $due,
            'cleaned' => $cleaned,
            // Cars we failed, kept apart from cars we could not help. A car the
            // owner had driven to work is not a service failure.
            'failed' => $logs->filter(fn (ServiceLog $l) => $l->outcome->isOurFault())->count(),
            'not_our_fault' => $logs->filter(
                fn (ServiceLog $l) => ! $l->outcome->wasCleaned() && ! $l->outcome->isOurFault()
            )->count(),
            // Due but never touched at all. Different from marked missed:
            // nobody even said what happened.
            'unaccounted' => max(0, $due - $logs->count()),
            'attendance' => Attendance::query()
                ->where('cleaner_id', $cleaner->id)
                ->whereDate('worked_on', $on)
                ->value('status')?->value,
        ];
    }
}
