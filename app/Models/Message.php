<?php

namespace App\Models;

use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to tell a customer something.
 *
 * Written whether or not it was actually delivered, so "did we tell them?" has
 * an answer, and so a suppressed message in development still shows what would
 * have gone out.
 */
class Message extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'customer_id', 'subscription_id',
        'channel', 'purpose', 'template', 'recipient', 'body',
        'status', 'suppressed_reason', 'provider_id', 'error', 'sent_at', 'sent_on',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => MessagePurpose::class,
            'status' => MessageStatus::class,
            'sent_at' => 'datetime',
            'sent_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->id ??= (string) \Illuminate\Support\Str::uuid7();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Messages that actually reached somebody. */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', MessageStatus::Sent);
    }
}
