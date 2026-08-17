<?php

namespace App\Support\Tenancy;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which sectors the current viewer covers.
 *
 * A sector *is* the territory. Staff are assigned sectors through user_sector,
 * a customer sits in one sector, and that is the whole of tenancy: if the
 * customer's sector is one of yours, you can see them.
 *
 * This replaced BranchContext, which asked the same question of a branch_id
 * copied onto every record. That copy was a cache of where somebody lived, and
 * like any cache it went stale - handing a sector to another franchise left two
 * hundred customers in the old owner's books while the sector said otherwise,
 * with nothing anywhere reporting the disagreement. There is no second copy to
 * drift now, so a reassignment is one pivot row and takes effect immediately.
 *
 * Fail-closed is unchanged and is the whole point: no sectors means no rows,
 * never every row.
 */
class SectorContext
{
    /** Set inside withoutScope(). */
    private static bool $unrestricted = false;

    /**
     * Explicit override, for console commands and jobs that run outside a
     * request and so have no signed in user to ask.
     *
     * @var array<int, string>|null
     */
    private static ?array $overrideSectorIds = null;

    private static bool $hasOverride = false;

    /**
     * Resolved sectors for the signed in user, for the life of one request.
     *
     * Memoised because the scope runs on every query and the answer cannot
     * change mid-request: a pivot edit takes effect on the next one.
     *
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    /**
     * Should queries be filtered at all?
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

        return ! $user->seesAllSectors();
    }

    /**
     * The sectors to filter by, or null when the caller is unrestricted.
     *
     * An empty array is not the same as null and must not be treated as one: it
     * means "covers nothing", and the scope turns it into no rows. Null means
     * "ask nothing of this query".
     *
     * @return array<int, string>|null
     */
    public static function currentSectorIds(?User $user = null): ?array
    {
        if (self::$unrestricted) {
            return null;
        }

        if (self::$hasOverride) {
            return self::$overrideSectorIds;
        }

        $user = $user ?: auth()->user();

        if (! $user) {
            return [];
        }

        /*
         * An administrator covers everything, which is null and not "every
         * sector listed out".
         *
         * Without this they read as covering nothing: they hold no pivot rows,
         * because assigning an administrator every sector by hand is exactly
         * the bookkeeping this model exists to avoid. The global scope never
         * noticed - it asks isRestricted() first - but anything calling this
         * directly, like the dashboard's sector filter, saw an empty list and
         * refused them their own data.
         */
        if ($user->seesAllSectors()) {
            return null;
        }

        return self::$cache[$user->id] ??= DB::table('user_sector')
            ->where('user_id', $user->id)
            ->pluck('sector_id')
            ->all();
    }

    /**
     * The customer this viewer *is*, when they are one.
     *
     * A customer holds no sectors and never will - they are not staff, and
     * putting them in the pivot would make them able to see their neighbours.
     * They see their own records instead, which is a different question with a
     * different answer, so the scope asks this one for them.
     *
     * Read straight from the table: this runs inside the global scope that
     * would otherwise call itself.
     */
    public static function currentCustomerId(?User $user = null): ?string
    {
        if (self::$unrestricted || self::$hasOverride) {
            return null;
        }

        $user = $user ?: auth()->user();

        if (! $user || $user->role !== UserRole::Customer) {
            return null;
        }

        return DB::table('customers')->where('user_id', $user->id)->value('id');
    }

    /**
     * Run a block across every sector.
     *
     * Deliberately verbose and easy to grep for: this is the only way past the
     * scope, and every use of it should be obvious in review.
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
     * Run a block as though it covered exactly these sectors.
     *
     * For scheduled jobs and queue workers, which have no authenticated user
     * but still need to act inside a territory.
     *
     * @template T
     *
     * @param  array<int, string>|string|null  $sectorIds
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forSectors(array|string|null $sectorIds, callable $callback)
    {
        $previousIds = self::$overrideSectorIds;
        $previousHas = self::$hasOverride;

        self::$overrideSectorIds = $sectorIds === null ? [] : (array) $sectorIds;
        self::$hasOverride = true;

        try {
            return $callback();
        } finally {
            self::$overrideSectorIds = $previousIds;
            self::$hasOverride = $previousHas;
        }
    }

    /**
     * Narrow to some sectors, or leave the caller's own scope in place.
     *
     * Null does NOT mean "everything": it means "whatever this user is already
     * allowed to see", which for a franchise user is still only their own
     * sectors. Only an administrator's own scope is unrestricted.
     *
     * @template T
     *
     * @param  array<int, string>|string|null  $sectorIds
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forSectorsOrOwn(array|string|null $sectorIds, callable $callback)
    {
        return $sectorIds === null || $sectorIds === []
            ? $callback()
            : self::forSectors($sectorIds, $callback);
    }

    /**
     * Forget any override, and anything memoised. Used between tests.
     */
    public static function reset(): void
    {
        self::$unrestricted = false;
        self::$overrideSectorIds = null;
        self::$hasOverride = false;
        self::$cache = [];
    }

    /**
     * Drop one user's memoised sectors, after their assignment changes.
     */
    public static function forget(string $userId): void
    {
        unset(self::$cache[$userId]);
    }
}
