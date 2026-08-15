<?php

namespace App\Models;

use App\Enums\ServiceOutcome;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One car, one day, one outcome.
 *
 * This is the record that answers the question customers actually ask - "was my
 * car done on Tuesday?" - which v1 could not answer at all, because it only
 * stored a daily count per cleaner.
 */
class ServiceLog extends BaseModel
{
    use BelongsToBranch;

    protected $attributes = [
        'outcome' => 'cleaned',
    ];

    protected $fillable = [
        'branch_id', 'vehicle_id', 'subscription_id', 'cleaner_id',
        'serviced_on', 'serviced_at', 'outcome', 'note',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'outcome' => ServiceOutcome::class,
            'serviced_on' => 'date',
            'serviced_at' => 'datetime',
        ]);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaner_id');
    }

    public function scopeOn(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('serviced_on', $date);
    }

    /** Cars actually cleaned. The only outcome that counts as work done. */
    public function scopeCleaned(Builder $query): Builder
    {
        return $query->where('outcome', ServiceOutcome::Cleaned);
    }

    /**
     * Cars we failed, as opposed to cars we could not help.
     *
     * A car the owner had driven to work is not a service failure; a car nobody
     * turned up for is. Reporting them together is how v1 hid the difference.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('outcome', [ServiceOutcome::Missed, ServiceOutcome::AccessDenied]);
    }
}
