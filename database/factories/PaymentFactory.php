<?php

namespace Database\Factories;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Tenancy\SectorContext;
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
            // A customer in the same territory - see ComplaintFactory for why.
            'customer_id' => fn (array $attributes) => SectorContext::withoutScope(
                fn () => Customer::factory()->create(['branch_id' => $attributes['branch_id'] ?? null])->id
            ),
            'subscription_id' => fn (array $attributes) => SectorContext::withoutScope(
                fn () => Subscription::factory()->create([
                    'branch_id' => $attributes['branch_id'] ?? null,
                    'customer_id' => $attributes['customer_id'],
                ])->id
            ),

            /*
             * The territory the money was taken in, stamped as the real thing
             * stamps it - from the customer, at the moment of the payment.
             *
             * Without this a factory-made payment carries no sector and is
             * invisible to every sector-scoped user, which is the correct rule
             * applied to a row that simply forgot to say where it came from.
             */
            'sector_id' => fn (array $attributes) => SectorContext::withoutScope(
                fn () => Customer::withTrashed()->whereKey($attributes['customer_id'])->value('sector_id')
            ),
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
            'sector_id' => SectorContext::withoutScope(
                fn () => Customer::withTrashed()->whereKey($subscription->customer_id)->value('sector_id')
            ),
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
