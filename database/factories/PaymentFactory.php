<?php

namespace Database\Factories;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            'subscription_id' => Subscription::factory(),
            'purpose' => PaymentPurpose::Subscription,
            // The default is an attempt in flight, because that is what a
            // payment is for most of its short life.
            'status' => PaymentStatus::Initiated,
            'amount_paise' => 85000,
            'currency' => 'INR',
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::lower(Str::random(14)),
        ];
    }

    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn () => [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'branch_id' => $subscription->branch_id,
            'amount_paise' => $subscription->amount_paise,
        ]);
    }

    public function captured(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Captured,
            'gateway_payment_id' => 'pay_'.Str::lower(Str::random(14)),
            'method' => 'upi',
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Failed]);
    }
}
