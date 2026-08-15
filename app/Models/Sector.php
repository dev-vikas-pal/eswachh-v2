<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A geographic sector, serviced by one branch.
 *
 * Branch scoped, so a franchise owner only ever sees the sectors they operate.
 */
class Sector extends BaseModel
{
    use BelongsToBranch;

    protected $fillable = ['area_id', 'branch_id', 'name', 'status'];

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
