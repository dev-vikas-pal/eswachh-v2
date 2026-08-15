<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Franchise',
            'code' => strtoupper(fake()->unique()->bothify('BR-###')),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->numerify('9#########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
