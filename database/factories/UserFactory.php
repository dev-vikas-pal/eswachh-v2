<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
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

    private function forRole(UserRole $role, Branch|string|null $branch): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'branch_id' => $branch instanceof Branch ? $branch->id : $branch,
        ]);
    }
}
