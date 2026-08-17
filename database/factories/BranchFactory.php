<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Sector;
use App\Support\Tenancy\SectorContext;
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

    /**
     * Every branch made in a test gets a sector.
     *
     * Branches decide nothing now - user_sector does - but the suite still says
     * "a franchise owner of this branch" and means "somebody who covers what
     * this branch covers". Creating the sector here, before the staff and
     * customers that follow, is what lets both pick up the same one and see
     * each other. Without it a test would build a branch, an owner and a
     * customer that share nothing at all.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Branch $branch) {
            SectorContext::withoutScope(fn () => Sector::factory()->create([
                'branch_id' => $branch->id,
            ]));
        });
    }
}
