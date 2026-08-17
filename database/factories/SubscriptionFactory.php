<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            // A customer in the same territory - see ComplaintFactory for why.
            'customer_id' => fn (array $attributes) => SectorContext::withoutScope(
                fn () => Customer::factory()->create(['branch_id' => $attributes['branch_id'] ?? null])->id
            ),
            'vehicle_id' => Vehicle::factory(),
            'duration_id' => Duration::factory(),
            'sequence' => 1,
            'period_start' => Carbon::today(),
            'period_end' => Carbon::today()->addMonth(),
            'status' => SubscriptionStatus::Active,
            'amount_paise' => 85000,
            'paid_amount_paise' => 85000,
        ];
    }

    /**
     * Wire branch, customer and vehicle together so the tenancy scope sees a
     * coherent record rather than three unrelated branches.
     */
    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn () => [
            'vehicle_id' => $vehicle->id,
            'customer_id' => $vehicle->customer_id,
            'branch_id' => $vehicle->branch_id,
        ]);
    }

    /** Sold but not yet paid for. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Pending,
            'paid_amount_paise' => 0,
        ]);
    }

    /** Running, but past its renewal date. */
    public function expired(int $daysAgo = 3): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Active,
            'period_start' => Carbon::today()->subMonth()->subDays($daysAgo),
            'period_end' => Carbon::today()->subDays($daysAgo),
        ]);
    }
}
