<?php

namespace App\Models;

/**
 * Location reference data. Not branch scoped: geography is shared, and it is
 * the sector that records which branch services it.
 */
class Society extends BaseModel
{
    protected $fillable = ["sector_id","name","surcharge_paise","status"];

    public function sector(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
