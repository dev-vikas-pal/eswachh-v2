<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Branch;
use App\Models\City;
use App\Models\Sector;
use App\Models\State;
use App\Support\Tenancy\BranchContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    protected $model = Sector::class;

    public function definition(): array
    {
        return [
            // Built unscoped: the factory must be able to set up geography
            // regardless of who is "logged in" during a test.
            'area_id' => BranchContext::withoutScope(fn () => self::anArea()->id),
            'name' => 'Sector '.fake()->unique()->numberBetween(1, 999),
            'status' => true,
        ];
    }

    public function forBranch(Branch|string $branch): static
    {
        return $this->state(fn () => [
            'branch_id' => $branch instanceof Branch ? $branch->id : $branch,
        ]);
    }

    /**
     * One shared area, so sector tests do not each build a whole hierarchy.
     */
    private static function anArea(): Area
    {
        $area = Area::query()->first();

        if ($area) {
            return $area;
        }

        $state = State::create(['name' => 'Uttar Pradesh', 'status' => true]);
        $city = City::create(['state_id' => $state->id, 'name' => 'Greater Noida', 'status' => true]);

        return Area::create(['city_id' => $city->id, 'name' => 'Greater Noida East', 'status' => true]);
    }
}
