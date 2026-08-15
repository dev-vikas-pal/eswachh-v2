<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Somebody the public should know about.
 */
class TeamMember extends BaseModel
{
    protected $attributes = ['sort_order' => 0, 'status' => true];

    protected $fillable = ['name', 'title', 'bio', 'photo_path', 'sort_order', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', true)->orderBy('sort_order')->orderBy('name');
    }
}
