<?php

namespace App\Models;

/**
 * Location reference data. Not branch scoped: geography is shared, and it is
 * the sector that records which branch services it.
 */
class City extends BaseModel
{
    protected $fillable = ["state_id","name","status"];

    public function state(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function areas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Area::class);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
