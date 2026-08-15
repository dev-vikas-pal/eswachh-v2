<?php

namespace App\Models;

use App\Enums\ClothEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a cloth balance.
 *
 * Not a BaseModel, for the same reason ComplaintEvent is not: this is a ledger.
 * Entries are written once. A wrong entry is corrected by an adjustment that
 * says so, never by editing history - otherwise the balance and the ledger can
 * disagree and neither can be trusted.
 */
class ClothEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'subscription_id', 'customer_id',
        'type', 'quantity', 'balance_after',
        'payment_id', 'service_log_id', 'cloth_bundle_id',
        'reason', 'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClothEntryType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->id ??= (string) \Illuminate\Support\Str::uuid7();
            $entry->created_at ??= now();
        });

        static::updating(fn () => throw new \LogicException(
            'A cloth entry cannot be changed. Post an adjustment instead.'
        ));

        static::deleting(fn () => throw new \LogicException(
            'A cloth entry cannot be deleted. Post an adjustment instead.'
        ));
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
