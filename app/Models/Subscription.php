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
