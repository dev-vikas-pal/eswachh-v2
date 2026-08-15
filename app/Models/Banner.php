<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A headline on the public home page.
 *
 * Not branch scoped: there is one public website, whatever the franchise
 * arrangement behind it.
 */
class Banner extends BaseModel
{
    protected $attributes = ['sort_order' => 0, 'status' => true];

    protected $fillable = [
        'headline', 'subheadline', 'eyebrow',
        'cta_label', 'cta_route', 'secondary_label', 'secondary_route',
        'image_path', 'starts_at', 'ends_at', 'sort_order', 'status',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => 'boolean',
        ]);
    }

    /**
     * Banners that should be on the site right now.
     *
     * A null date means "no limit at that end", so a permanent banner needs no
     * dates at all and a festival offer takes itself down.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where('status', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    public function isScheduled(): bool
    {
        return $this->starts_at !== null || $this->ends_at !== null;
    }
}
