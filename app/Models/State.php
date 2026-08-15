<?php

namespace App\Models;

/**
 * Location reference data. Not branch scoped: geography is shared, and it is
 * the sector that records which branch services it.
 */
class State extends BaseModel
{
    protected $fillable = ["name","status"];

    public function cities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(City::class);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
