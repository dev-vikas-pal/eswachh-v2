<?php

namespace App\Models;

use App\Models\Concerns\ScopedToSectors;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A geographic sector: the territory, and the unit the business is divided by.
 *
 * There is no franchise above this. A sector is what staff are assigned and
 * what a customer sits in, so "who services this customer" is answered by
 * comparing the two - see ScopedToSectors. One person may cover several
 * sectors, and several people may cover one.
 */
class Sector extends BaseModel
{
    use ScopedToSectors;

    /** The territory itself: you see the ones you are assigned. */
    protected static function sectorScope(): array
    {
        return ['self' => true];
    }

    protected $fillable = ['area_id', 'branch_id', 'name', 'status'];

    /**
     * Staff who cover this sector.
     *
     * User carries no global scope of its own - see the note on User::scopeVisible
     * - so this needs no unscoping, unlike the relation in the other direction.
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sector')->withTimestamps();
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => 'boolean',
        ]);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function societies(): HasMany
    {
        return $this->hasMany(Society::class);
    }
}
