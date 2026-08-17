<?php

namespace App\Models;

use App\Models\Concerns\ScopedToSectors;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone who buys the service.
 *
 * Separate from User on purpose: a franchise owner can register a walk-in
 * customer who has never logged in, and the address belongs to the person
 * being serviced rather than to an authentication record.
 */
class Customer extends BaseModel
{
    use ScopedToSectors;

    /**
     * The customer's own sector is the fact everything else is measured
     * against, so this table matches it directly rather than deriving it.
     *
     * Deliberately not through society. A society tells you the surcharge and
     * the address; the sector tells you whose territory it is, and making the
     * second depend on the first meant a customer with no society recorded
     * belonged to nobody.
     */
    protected static function sectorScope(): array
    {
        return ['sector' => 'sector_id', 'customer' => 'id'];
    }

    protected $fillable = [
        'branch_id', 'user_id', 'name', 'phone', 'email',
        'state_id', 'city_id', 'area_id', 'sector_id', 'society_id',
        'house_no', 'address', 'preferred_time', 'status',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Has this customer been given a login yet? */
    public function hasLogin(): bool
    {
        return $this->user_id !== null;
    }
}
