<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Applied to every model whose visibility follows a sector.
 *
 * The scope is global, so a query is filtered whether or not the person who
 * wrote it remembered to. That is the whole point: in v1 scoping was applied per
 * query, so any screen that forgot it leaked across franchises.
 *
 * Almost everything reduces to one question - *which customers may this viewer
 * see* - and then keys off that. Each model says how it reaches a customer:
 *
 *     protected static function sectorScope(): array
 *     {
 *         return ['customer' => 'id', 'sector' => 'sector_id'];   // customers
 *         return ['customer' => 'customer_id'];                   // one hop
 *         return ['customer' => 'subscription_id', 'through' => 'subscriptions'];
 *     }
 *
 * Three tables do not reduce to that and say so instead:
 *
 *     'sector' => 'sector_id'   payments, matched on the stamp they carry
 *     'staff'  => 'cleaner_id'  attendance, which belongs to a person
 *     'self'   => true          sectors themselves
 *
 * Everything below uses the query builder rather than Eloquent, deliberately.
 * These subqueries run inside the global scope that would otherwise call them
 * again, and they must see soft-deleted rows: withdrawing a society should not
 * hide its customers from the person still servicing them.
 */
trait ScopedToSectors
{
    public static function bootScopedToSectors(): void
    {
        static::addGlobalScope('sector', function (Builder $query) {
            if (! SectorContext::isRestricted()) {
                return;
            }

            $rules = static::sectorScope();
            $model = $query->getModel();

            /*
             * A customer sees their own records, not a territory.
             *
             * Asked first because a customer holds no sectors and never will -
             * they are not staff, and putting them in the pivot would let them
             * see their neighbours. Without this they would fall through to the
             * covers-nothing case and lose sight of their own plan.
             */
            $customerId = SectorContext::currentCustomerId();

            if ($customerId !== null) {
                self::narrowToOwnRecords($query, $model, $rules, $customerId);

                return;
            }

            $sectorIds = SectorContext::currentSectorIds();

            if ($sectorIds === null || $sectorIds === []) {
                // Restricted, but covering nothing. Return nothing rather than
                // everything - the difference between a quiet screen and a leak.
                $query->whereRaw('1 = 0');

                return;
            }

            self::narrowToSectors($query, $model, $rules, $sectorIds);
        });
    }

    /**
     * How this table reaches the sector that decides who may see it.
     *
     * @return array<string, mixed>
     */
    protected static function sectorScope(): array
    {
        return ['customer' => 'customer_id'];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $sectorIds
     */
    private static function narrowToSectors(Builder $query, Model $model, array $rules, array $sectorIds): void
    {
        if ($rules['self'] ?? false) {
            $query->whereIn($model->qualifyColumn('id'), $sectorIds);

            return;
        }

        if ($staffKey = $rules['staff'] ?? null) {
            // Whoever covers any of these sectors. Attendance is a person's
            // record, not a customer's, so it is the only one asked this way.
            $query->whereIn($model->qualifyColumn($staffKey), self::staffCovering($sectorIds));

            return;
        }

        /*
         * A stamped sector wins over anything derivable.
         *
         * Payments carry one, written at capture and never recomputed, because
         * a payment records something that happened: who took the money does
         * not change when the territory is rearranged afterwards.
         */
        if ($sectorKey = $rules['sector'] ?? null) {
            $query->whereIn($model->qualifyColumn($sectorKey), $sectorIds);

            return;
        }

        self::attachCustomers($query, $model, $rules, self::customersIn($sectorIds));
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function narrowToOwnRecords(Builder $query, Model $model, array $rules, string $customerId): void
    {
        if ($rules['self'] ?? false) {
            // The sector they live in, so their own address renders.
            $query->whereIn(
                $model->qualifyColumn('id'),
                DB::table('customers')->select('sector_id')->where('id', $customerId),
            );

            return;
        }

        if ($rules['staff'] ?? null) {
            // Who worked which day is nobody's business but the office's.
            $query->whereRaw('1 = 0');

            return;
        }

        self::attachCustomers($query, $model, $rules, [$customerId]);
    }

    /**
     * Hang a set of customers off this table, directly or through one hop.
     *
     * @param  array<string, mixed>  $rules
     * @param  QueryBuilder|array<int, string>  $customers
     */
    private static function attachCustomers(Builder $query, Model $model, array $rules, $customers): void
    {
        $key = $rules['customer'] ?? 'customer_id';

        // The customers table itself: its own key is the customer.
        if ($key === 'id' && ($rules['through'] ?? null) === null) {
            $query->whereIn($model->qualifyColumn('id'), $customers);

            return;
        }

        if ($through = $rules['through'] ?? null) {
            $query->whereIn(
                $model->qualifyColumn($key),
                DB::table($through)->select('id')->whereIn('customer_id', $customers),
            );

            return;
        }

        $query->whereIn($model->qualifyColumn($key), $customers);
    }

    /**
     * The customers living in these sectors.
     *
     * A null sector_id never matches an IN, which is exactly the rule: a
     * customer with no sector belongs to no territory, and stays invisible to
     * sector-scoped staff until somebody gives them one.
     *
     * @param  array<int, string>  $sectorIds
     */
    private static function customersIn(array $sectorIds): QueryBuilder
    {
        return DB::table('customers')->select('id')->whereIn('sector_id', $sectorIds);
    }

    /**
     * Staff covering any of these sectors.
     *
     * @param  array<int, string>  $sectorIds
     */
    private static function staffCovering(array $sectorIds): QueryBuilder
    {
        return DB::table('user_sector')->select('user_id')->whereIn('sector_id', $sectorIds);
    }

    /**
     * Escape the scope for one query.
     *
     * Named so it is obvious in review. Prefer SectorContext::withoutScope()
     * for a block of work.
     */
    public function scopeAcrossSectors(Builder $query): Builder
    {
        return $query->withoutGlobalScope('sector');
    }
}
