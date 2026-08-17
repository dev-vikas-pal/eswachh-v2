<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Sector;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('9#########'),
            'role' => UserRole::Customer,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'status' => true,
            'branch_id' => null,
        ];
    }

    /** Sees every branch; belongs to none. */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::SuperAdmin,
            'branch_id' => null,
        ]);
    }

    public function franchiseOwner(Branch|string|null $branch = null): static
    {
        return $this->forRole(UserRole::FranchiseOwner, $branch);
    }

    public function cleaner(Branch|string|null $branch = null): static
    {
        return $this->forRole(UserRole::Cleaner, $branch);
    }

    public function customer(Branch|string|null $branch = null): static
    {
        return $this->forRole(UserRole::Customer, $branch);
    }

    /**
     * A branch user with no branch. Exists so the fail-closed behaviour can be
     * tested: this person must see nothing, not everything.
     */
    public function withoutBranch(): static
    {
        return $this->state(fn () => ['branch_id' => null]);
    }

    /**
     * Give this person some territory.
     *
     * @param  iterable<int, Sector|string>|Sector|string  $sectors
     */
    public function coveringSectors(iterable|Sector|string $sectors): static
    {
        $ids = collect(is_iterable($sectors) ? $sectors : [$sectors])
            ->map(fn ($s) => $s instanceof Sector ? $s->id : $s)
            ->all();

        return $this->afterCreating(fn (User $user) => $user->sectors()->syncWithoutDetaching($ids));
    }

    private function forRole(UserRole $role, Branch|string|null $branch): static
    {
        $factory = $this->state(fn () => [
            'role' => $role,
            'branch_id' => $branch instanceof Branch ? $branch->id : $branch,
        ]);

        /*
         * Staff given a branch get that branch's sectors.
         *
         * Branches no longer decide anything - user_sector does - but the test
         * suite says "a franchise owner of this branch" several hundred times,
         * and it means "somebody who covers what this branch covers". Honouring
         * that keeps the setup honest without rewriting every file to say the
         * same thing a longer way.
         *
         * Customers are excluded: their territory comes from their address, and
         * putting them in the pivot would let them see their neighbours.
         */
        if ($branch === null || $role === UserRole::Customer) {
            return $factory;
        }

        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        return $factory->afterCreating(function (User $user) use ($branchId) {
            $sectorIds = SectorContext::withoutScope(
                fn () => Sector::query()->where('branch_id', $branchId)->pluck('id')->all()
            );

            if ($sectorIds !== []) {
                $user->sectors()->syncWithoutDetaching($sectorIds);
            }
        });
    }
}
