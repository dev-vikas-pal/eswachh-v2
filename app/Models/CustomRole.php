<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Access\Abilities;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role the business defined for itself - a Supervisor, a Billing clerk.
 *
 * It refines a built-in role rather than replacing it. The base role decides
 * branch scoping and whether the holder is staff; this decides what they may
 * do. Nothing here can widen the first two, which is the point: a permission
 * screen that can accidentally grant sight of every franchise is worse than no
 * permission screen.
 */
class CustomRole extends BaseModel
{
    protected $fillable = ['name', 'description', 'base_role', 'abilities', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'abilities' => 'array',
            'status' => 'boolean',
            'base_role' => UserRole::class,
        ]);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * What somebody holding this role may actually do.
     *
     * Filtered on the way out as well as validated on the way in. An ability
     * that was removed from the application - a screen that no longer exists -
     * would otherwise sit in the JSON forever and read as though it still
     * granted something.
     *
     * @return array<int, string>
     */
    public function grants(): array
    {
        if (! $this->status) {
            // A switched-off role grants nothing. The account still works,
            // falling back to its built-in role, rather than being locked out.
            return [];
        }

        return array_values(array_intersect($this->abilities ?? [], Abilities::grantable()));
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->grants(), true);
    }
}
