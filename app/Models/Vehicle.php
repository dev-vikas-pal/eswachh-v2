<?php

namespace App\Models;

use App\Models\Concerns\ScopedToSectors;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A car on the round.
 *
 * The assigned cleaner lives here rather than on the subscription: a cleaner
 * services a vehicle, and renewing the subscription must not change who turns
 * up in the morning.
 */
class Vehicle extends BaseModel
{
    use ScopedToSectors;

    protected $fillable = [
        'branch_id', 'customer_id', 'vehicle_model_id',
        'registration', 'assigned_cleaner_id', 'status',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_cleaner_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The period currently in force, if any. */
    public function currentSubscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany('sequence');
    }

    public function setRegistrationAttribute($value): void
    {
        // Registrations are compared and searched constantly; store one form.
        $this->attributes['registration'] = strtoupper(preg_replace('/\s+/', '', (string) $value));
    }
}
