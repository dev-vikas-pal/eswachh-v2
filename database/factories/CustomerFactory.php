<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Sector;
use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),

            /*
             * A sector, always.
             *
             * This is what decides who can see them - a customer with none is
             * invisible to every sector-scoped user, which is the intended rule
             * and a baffling default for a factory. Callers that care pass their
             * own; callers that do not still get a customer somebody can find.
             */
            'sector_id' => fn (array $attributes) => SectorContext::withoutScope(function () use ($attributes) {
                $branchId = $attributes['branch_id'] ?? null;

                // Reuse the branch's sector rather than making one each time,
                // so the staff created alongside them already cover it.
                return (Sector::query()->where('branch_id', $branchId)->first()
                    ?: Sector::factory()->create(['branch_id' => $branchId]))->id;
            }),

            'name' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'house_no' => fake()->buildingNumber(),
            'address' => fake()->streetAddress(),
            'status' => true,
        ];
    }
}
