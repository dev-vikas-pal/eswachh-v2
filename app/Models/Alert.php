<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Something that needs a person's attention.
 *
 * Belongs to a branch rather than to a user, so somebody who joins tomorrow
 * still sees what is outstanding today.
 */
class Alert extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'type', 'severity', 'title', 'body',
        'link_route', 'link_params', 'dedupe_key', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'link_params' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $alert) => $alert->id ??= (string) \Illuminate\Support\Str::uuid7());
    }

    /** Everyone who has already looked at this one. */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alert_reads')->withPivot('read_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Alerts this person should see.
     *
     * A super admin sees business-wide ones and every branch's; everyone else
     * sees their own branch, and the business-wide ones are not theirs to act
     * on.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllSectors()) {
            return $query;
        }

        return $query->where('branch_id', $user->branch_id);
    }
}
