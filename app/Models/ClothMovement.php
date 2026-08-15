<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cloths collected from a car, or returned to it.
 *
 * Where the cloths physically are, as opposed to how many a customer has paid
 * for. The ledger answers the second question; this answers the first.
 */
class ClothMovement extends BaseModel
{
    use BelongsToBranch;

    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    protected $fillable = [
        'branch_id', 'vehicle_id', 'subscription_id', 'cleaner_id',
        'direction', 'cloth_count', 'moved_on', 'note',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'moved_on' => 'date',
            'cloth_count' => 'integer',
        ]);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaner_id');
    }

    public function scopeOn(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('moved_on', $date);
    }

    public function scopePickups(Builder $query): Builder
    {
        return $query->where('direction', self::PICKUP);
    }

    public function scopeDeliveries(Builder $query): Builder
    {
        return $query->where('direction', self::DELIVERY);
    }

    /**
     * Cloths collected and not yet returned.
     *
     * The number that matters: cloths sitting in a laundry somewhere are cloths
     * a customer has paid for and cannot use.
     */
    public static function outstanding(?string $branchId = null): int
    {
        $picked = static::query()->pickups()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('cloth_count');
        $returned = static::query()->deliveries()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('cloth_count');

        return (int) $picked - (int) $returned;
    }
}
