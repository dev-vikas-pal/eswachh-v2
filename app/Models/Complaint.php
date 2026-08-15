<?php

namespace App\Models;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Complaint extends BaseModel
{
    use BelongsToBranch;

    protected $attributes = [
        'category' => 'other',
        'priority' => 'normal',
        'status' => 'open',
        'reopened_count' => 0,
    ];

    protected $fillable = [
        'branch_id', 'reference', 'customer_id', 'vehicle_id', 'subscription_id',
        'category', 'priority', 'description', 'status',
        'assigned_to', 'assigned_at', 'due_at',
        'resolved_at', 'resolved_by', 'resolution_note',
        'closed_at', 'closed_by', 'reopened_count',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => ComplaintStatus::class,
            'category' => ComplaintCategory::class,
            'priority' => ComplaintPriority::class,
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The trail, oldest first, because that is the order it is read in. */
    public function events(): HasMany
    {
        return $this->hasMany(ComplaintEvent::class)->orderBy('created_at');
    }

    /**
     * Past the time we promised, and still nobody's answer.
     *
     * Derived from due_at and the clock, never stored: a stored flag is correct
     * only until the next minute passes.
     */
    public function isOverdue(): bool
    {
        return $this->status->isLive()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /** How long it took, or how long it has been so far. */
    public function ageInHours(): int
    {
        return (int) $this->created_at?->diffInHours($this->resolved_at ?? Carbon::now());
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [ComplaintStatus::Open, ComplaintStatus::Assigned]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->live()->whereNotNull('due_at')->where('due_at', '<', Carbon::now());
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->where('status', ComplaintStatus::Open)->whereNull('assigned_to');
    }
}
