<?php

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Sorting for list screens, from a whitelist.
 *
 * The column comes from the query string, so it goes into SQL. A whitelist per
 * screen is the whole point: passing the parameter through would let anyone
 * order by a column they cannot see - or by a subquery - and turn a list
 * endpoint into a way to read the rest of the table one sort at a time.
 *
 * Anything not on the list is ignored rather than rejected, so an old
 * bookmarked URL still returns the list instead of an error.
 */
trait SortsLists
{
    /**
     * @param  array<string, string>  $allowed  Parameter name => column or expression
     */
    protected function applySort(Builder $query, Request $request, array $allowed, string $default): Builder
    {
        $requested = (string) $request->query('sort', '');
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        /*
         * Nothing asked for, and the caller has its own default - a complaint
         * queue ordered by urgency, say - so leave it alone. Only a whitelisted
         * request replaces it. Overwriting a deliberate default with a plain
         * column sort would quietly break the one screen whose order is the
         * point of the screen.
         */
        if ($requested === '' && ! isset($allowed[$default])) {
            return $query;
        }

        $column = $allowed[$requested] ?? $allowed[$default] ?? null;

        if ($column === null) {
            return $query;
        }

        // Several columns for one heading - "customer" meaning name then phone -
        // so a list sorts the way a person expects rather than by one field.
        foreach (explode(',', $column) as $part) {
            $query->orderBy(trim($part), $direction);
        }

        return $query;
    }

    /**
     * What the screen may sort by, so the headings are not hard-coded twice.
     *
     * @param  array<string, string>  $allowed
     * @return array<string, mixed>
     */
    protected function sortMeta(Request $request, array $allowed, string $default): array
    {
        $requested = (string) $request->query('sort', '');

        return [
            'sortable' => array_keys($allowed),
            'sort' => isset($allowed[$requested]) ? $requested : $default,
            'direction' => strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc',
        ];
    }
}
