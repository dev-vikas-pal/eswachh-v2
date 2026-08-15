<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a complaint's trail.
 *
 * Deliberately not a BaseModel: no updated_at, no soft delete, no audit
 * columns. Entries are written once and never touched, which is the whole
 * point - v1 kept a single resolution note that whoever wrote last overwrote,
 * so nobody could say what had been promised to the customer earlier.
 *
 * Corrections are new entries, not edits.
 */
class ComplaintEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'complaint_id', 'branch_id', 'type',
        'from_status', 'to_status', 'note', 'actor_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->id ??= (string) \Illuminate\Support\Str::uuid7();
            $event->created_at ??= now();
        });

        // Enforced in code as well as by convention: an append only table that
        // relies on everyone remembering is not append only.
        static::updating(fn () => throw new \LogicException(
            'A complaint event cannot be changed. Add another entry instead.'
        ));

        static::deleting(fn () => throw new \LogicException(
            'A complaint event cannot be deleted.'
        ));
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
