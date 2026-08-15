<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            // Indian format, so test data reads like the real thing.
            'registration' => strtoupper(fake()->unique()->bothify('??##??####')),
            'status' => true,
        ];
    }

    /** Keep the vehicle in the same branch as its customer. */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'branch_id' => $customer->branch_id,
        ]);
    }
}
