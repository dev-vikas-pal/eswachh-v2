<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether a cleaner worked on a given day.
 *
 * Just the register. What they did while working is a ServiceLog per car, so
 * "cars serviced" is counted from real events rather than typed in - which is
 * what v1 did, and why its numbers could not be checked.
 */
class Attendance extends BaseModel
{
    use BelongsToBranch;

    protected $attributes = [
        'status' => 'present',
    ];

    protected $fillable = [
        'branch_id', 'cleaner_id', 'worked_on', 'status',
        'started_at', 'finished_at', 'marked_by', 'marked_at', 'note',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => AttendanceStatus::class,
            'worked_on' => 'date',
            'marked_at' => 'datetime',
        ]);
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaner_id');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /**
     * Filled in after the fact.
     *
     * A week of attendance entered on a Friday is not evidence of anything, so
     * it is visible rather than indistinguishable from a day marked as it
     * happened.
     */
    public function wasMarkedLate(): bool
    {
        return $this->marked_at !== null
            && $this->worked_on !== null
            && $this->marked_at->startOfDay()->greaterThan($this->worked_on->copy()->startOfDay());
    }

    public function scopeOn(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('worked_on', $date);
    }

    public function scopePresent(Builder $query): Builder
    {
        return $query->where('status', AttendanceStatus::Present);
    }

    public function scopeAbsent(Builder $query): Builder
    {
        return $query->where('status', AttendanceStatus::Absent);
    }
}
