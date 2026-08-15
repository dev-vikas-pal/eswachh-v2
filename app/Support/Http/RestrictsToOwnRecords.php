<?php

namespace App\Support\Http;

use App\Enums\UserRole;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Holds a customer to their own rows.
 *
 * An ability answers "may this person use this feature". It cannot answer
 * "which rows may they see", and the two get confused easily: a customer holds
 * view.subscription so they can look at their plan, and a list endpoint that
 * checks only the ability hands them the whole branch.
 *
 * Branch scoping does not help here. A customer belongs to a branch, so every
 * other customer of that franchise is inside their scope. This narrows to the
 * customer records that carry their user id - usually one, but a household with
 * two accounts on one login is not impossible, so it is a whereIn.
 *
 * Staff are left alone: their limit is the branch, and it is already applied.
 */
trait RestrictsToOwnRecords
{
    /**
     * @param  Builder<*>  $query
     * @param  string  $column  The column on this query that holds a customer id
     */
    protected function restrictToOwnRecords(Builder $query, Request $request, string $column = 'customer_id'): Builder
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::Customer) {
            return $query;
        }

        return $query->whereIn($column, Customer::query()
            ->where('user_id', $user->id)
            ->select('id'));
    }

    /**
     * Is this record the signed in customer's own?
     *
     * For reads of a single row, where a 404 is the right answer: telling
     * someone a plan exists but is not theirs is itself a disclosure.
     */
    protected function ownsRecord(Request $request, ?string $customerId): bool
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::Customer) {
            return true;
        }

        return $customerId !== null && Customer::query()
            ->where('user_id', $user->id)
            ->whereKey($customerId)
            ->exists();
    }
}
