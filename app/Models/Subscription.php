<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\ScopedToSectors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One paid period against one vehicle.
 *
 * Renewing creates the next period rather than editing this one, so the
 * history of a car is a list of rows you can read, and revenue reconciles
 * against periods instead of against a mutated total.
 */
class Subscription extends BaseModel
{
    use ScopedToSectors;

    protected $fillable = [
        'branch_id', 'vehicle_id', 'customer_id',
        'package_id', 'service_type_id', 'duration_id',
        'sequence', 'period_start', 'period_end', 'status',
        'amount_paise', 'paid_amount_paise',
        'cloth_service', 'cloth_bundle_id', 'cloth_balance',
        'held_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => SubscriptionStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'cloth_service' => 'boolean',
            'held_at' => 'datetime',
            'ended_at' => 'datetime',
        ]);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * The cloth plan bought with this subscription.
     *
     * The column has always been here; the relation was not, so anything that
     * wanted to name the plan - a message saying "Cloth ironing plan - yes,
     * Weekly 20" - had to look it up by hand or do without.
     */
    public function clothBundle(): BelongsTo
    {
        return $this->belongsTo(ClothBundle::class);
    }

    /**
     * The most recent money against this plan.
     *
     * Captured only: an abandoned checkout is not what somebody paid, and
     * showing it on the order would suggest a payment that never happened.
     */
    public function lastPayment(): HasOne
    {
        return $this->hasOne(Payment::class)
            ->where('status', PaymentStatus::Captured)
            ->latestOfMany('paid_at');
    }

    public function duration(): BelongsTo
    {
        return $this->belongsTo(Duration::class);
    }

    /**
     * Past its renewal date but still running.
     *
     * Derived, never stored. This is the group the daily reminder chases and
     * the weekly job eventually puts on hold.
     */
    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->period_end?->isBefore(Carbon::today());
    }

    /**
     * Where this plan stands against its own end date, said once.
     *
     * Every screen that offers a renewal has to answer the same question - "is
     * it too early for this?" - and four screens answering it four ways is four
     * chances to tell a customer something different. Worked out here, sent as
     * part of the plan, and only rendered by the front end.
     *
     * Renewing early is never refused. Blocking it would only mean the money
     * arrives later.
     */
    public function renewalTiming(): array
    {
        $end = $this->period_end;

        // Whole days, signed: positive is time left, negative is overdue.
        // Both ends are dates, so this never lands on a fraction.
        $days = $end ? (int) Carbon::today()->diffInDays($end, absolute: false) : null;

        $start = $this->nextPeriodStart();

        return [
            'renews_on' => $end?->toDateString(),
            'days_remaining' => $days,
            // Distinguished from "in date" because they read differently to a
            // customer: one is reassurance, the other is a nudge.
            'early' => $days !== null && $days > 0,
            'due_today' => $days === 0,
            'overdue' => $days !== null && $days < 0,
            'days_overdue' => $days !== null && $days < 0 ? -$days : 0,

            /*
             * What renewing right now would actually do to the dates.
             *
             * Sent rather than inferred, because the front end cannot work it
             * out: it depends on how long the plan runs for, and "overdue" on
             * its own does not decide it. A plan a week past its date continues
             * from that date; the same plan a year past it starts afresh. The
             * notice said "restarts it from today" for both until this was here.
             */
            'starts_from' => $this->continuesFromEndDate() ? 'end_date' : 'today',
            'next_period_start' => $start->toDateString(),
            'next_period_end' => $start->copy()->addMonths($this->monthsOnPlan())->toDateString(),
        ];
    }

    /** How many months one term of this plan runs for. */
    private function monthsOnPlan(): int
    {
        return max(1, (int) ($this->duration?->months ?? 1));
    }

    /**
     * Would renewing now carry on from the end date, or start again today?
     *
     * A plan is a chain of periods and the chain should not have holes in it: a
     * term runs from the day the last one ended, not from the day somebody got
     * round to paying. That is also what the customer received, because the
     * round keeps going through the grace period before anything is put on hold.
     *
     * The exception is a plan left unpaid so long that a term added to the old
     * end date would be over already - renewing a plan that lapsed eight months
     * ago cannot issue a month that expired in March. There is nothing left to
     * continue, so it begins now.
     *
     * Deliberately phrased as "would that period still be running?" rather than
     * as a rule about status or a grace-period count. Those are settings that
     * move; this cannot produce a plan that is expired on the day it is bought
     * whatever anybody sets.
     */
    public function continuesFromEndDate(): bool
    {
        $end = $this->period_end;

        return $end !== null && $end->copy()->addMonths($this->monthsOnPlan())->isFuture();
    }

    /**
     * The day a term bought right now would begin.
     *
     * The single answer to that question. RecordPayment writes the dates and
     * every renewal screen describes them, and those two disagreeing is how a
     * customer is told one thing and charged for another.
     */
    public function nextPeriodStart(): Carbon
    {
        return $this->continuesFromEndDate()
            ? $this->period_end->copy()
            : Carbon::today();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active)
            ->whereDate('period_end', '<', Carbon::today());
    }

    /** Running and still in date. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active)
            ->whereDate('period_end', '>=', Carbon::today());
    }

    public function scopeOnHold(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Hold);
    }

    /** Overdue by more than the grace period, so eligible for auto hold. */
    public function scopeOverdueBeyondGrace(Builder $query, int $graceDays = 7): Builder
    {
        return $query->where('status', SubscriptionStatus::Active)
            ->whereDate('period_end', '<', Carbon::today()->subDays($graceDays));
    }

    public function amount(): float
    {
        return $this->amount_paise / 100;
    }
}
