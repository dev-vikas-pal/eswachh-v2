<?php

namespace App\Support\Tenancy;

use App\Models\User;

/**
 * Which branch the current request is operating inside.
 *
 * This is the single place that answers "whose data may this user see?".
 * Everything else - the global scope, the API, reports - reads from here, so
 * there is one rule rather than one per screen.
 *
 * Three states:
 *   unrestricted   a super admin, or an explicitly unscoped block of work
 *   a branch id    a user who belongs to one branch
 *   restricted,
 *   with no branch a user who should see nothing at all
 *
 * The third state is the important one. A user with no branch sees nothing,
 * never everything: the failure mode is closed.
 */
class BranchContext
{
    /**
     * Set while a block of work is deliberately running across all branches.
     */
    private static bool $unrestricted = false;

    /**
     * Explicit branch override, used by console commands and jobs that run
     * outside a request and so have no logged in user.
     */
    private static ?string $overrideBranchId = null;

    private static bool $hasOverride = false;

    /**
     * Should queries be filtered by branch at all?
     */
    public static function isRestricted(?User $user = null): bool
    {
        if (self::$unrestricted) {
            return false;
        }

        if (self::$hasOverride) {
            return true;
        }

        $user = $user ?: auth()->user();

        if (! $user) {
            // No user and no override: nothing is in scope. Callers that
            // legitimately need everything must say so with withoutScope().
            return true;
        }

        return ! $user->seesAllBranches();
    }

    /**
     * The branch to filter by, or null when the caller is unrestricted.
     *
     * A restricted user with no branch returns null too, which is why callers
     * must check isRestricted() first rather than treating null as "all".
     */
    public static function currentBranchId(?User $user = null): ?string
    {
        if (self::$unrestricted) {
            return null;
        }

        if (self::$hasOverride) {
            return self::$overrideBranchId;
        }

        $user = $user ?: auth()->user();

        return $user?->branch_id;
    }

    /**
     * Run a block across every branch.
     *
     * Deliberately verbose and easy to grep for: this is the only way past the
     * branch scope, and every use of it should be obvious in review.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutScope(callable $callback)
    {
        $previous = self::$unrestricted;
        self::$unrestricted = true;

        try {
            return $callback();
        } finally {
            self::$unrestricted = $previous;
        }
    }

    /**
     * Run a block as though it belonged to one branch.
     *
     * For scheduled jobs and queue workers, which have no authenticated user
     * but still need to act inside a single branch.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forBranch(?string $branchId, callable $callback)
    {
        $previousId = self::$overrideBranchId;
        $previousHas = self::$hasOverride;

        self::$overrideBranchId = $branchId;
        self::$hasOverride = true;

        try {
            return $callback();
        } finally {
            self::$overrideBranchId = $previousId;
            self::$hasOverride = $previousHas;
        }
    }

    /**
     * Narrow to one branch, or leave the caller's own scope in place.
     *
     * Null does NOT mean "every branch": it means "whatever this user is
     * already allowed to see", which for a franchise owner is still just their
     * own. Only a super admin's own scope is unrestricted.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forBranchOrAll(?string $branchId, callable $callback)
    {
        return $branchId === null
            ? $callback()
            : self::forBranch($branchId, $callback);
    }

    /**
     * Forget any override. Used between tests.
     */
    public static function reset(): void
    {
        self::$unrestricted = false;
        self::$overrideBranchId = null;
        self::$hasOverride = false;
    }
}
