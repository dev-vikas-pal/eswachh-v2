<?php

namespace App\Models;

/**
 * Location reference data. Not branch scoped: geography is shared, and it is
 * the sector that records which branch services it.
 */
class Area extends BaseModel
{
    protected $fillable = ["city_id","name","status"];

    public function city(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function sectors(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Sector::class);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
