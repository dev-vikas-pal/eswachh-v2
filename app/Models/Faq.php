<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * One question and its answer, on the public site.
 *
 * In the database rather than in a template, so the office can answer a
 * question the day it starts being asked instead of waiting for a release.
 */
class Faq extends BaseModel
{
    protected $attributes = ['sort_order' => 0, 'status' => true];

    protected $fillable = ['question', 'answer', 'category', 'sort_order', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', true)->orderBy('sort_order')->orderBy('created_at');
    }
}
