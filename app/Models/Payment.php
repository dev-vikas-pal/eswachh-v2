<?php

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single attempt to take money.
 *
 * Written when the customer is sent to the gateway and completed in place when
 * they return, so an abandoned attempt is visible rather than absent.
 */
class Payment extends BaseModel
{
    use BelongsToBranch;

    /**
     * Mirrors the schema defaults.
     *
     * A column default only applies in the database, so a freshly created model
     * would hold null for these until it was read back - and anything that
     * touched the enum in between would fail on a null. Declared here so the
     * object in memory matches the row on disk.
     */
    protected $attributes = [
        'purpose' => 'subscription',
        'status' => 'initiated',
        'currency' => 'INR',
        'gateway' => 'razorpay',
    ];

    protected $fillable = [
        'branch_id', 'customer_id', 'subscription_id',
        'purpose', 'status', 'amount_paise', 'currency',
        'gateway', 'gateway_order_id', 'gateway_payment_id',
        'method', 'reference', 'paid_at', 'invoice_number',
        'verified_by', 'verified_at', 'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => PaymentStatus::class,
            'purpose' => PaymentPurpose::class,
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Money actually taken. Every revenue figure goes through this scope, so
     * there is one definition of income rather than one per report.
     */
    public function scopeRevenue(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Captured);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('paid_at', [$from, $to]);
    }

    public function amount(): float
    {
        return $this->amount_paise / 100;
    }

    public function wasSetByHand(): bool
    {
        return $this->verified_at !== null;
    }
}
