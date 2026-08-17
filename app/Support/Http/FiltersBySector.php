<?php

namespace App\Support\Http;

use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The sector picker in the top bar, applied to a listing.
 *
 * A convenience, never a grant. The global scope has already decided what this
 * person may see; this only narrows it further, so asking for somebody else's
 * sector returns nothing rather than opening anything.
 *
 * It exists as a trait because the same three lines were needed on five
 * screens, and four of them had it only in the front end's cache key - so
 * choosing a sector refetched the identical unfiltered list and the screen
 * appeared to ignore the control entirely.
 */
trait FiltersBySector
{
    /**
     * Narrow a listing to the sector the screen asked for, if any.
     *
     * @param  string  $via  How this table reaches a sector:
     *                       'sector' for its own column, 'customer' through
     *                       customer_id, 'subscription' through the plan.
     */
    /**
     * The sector the screen asked for, once it is known to be theirs.
     *
     * Null when the picker is on "all". Separate from applying it, because some
     * screens list staff rather than customers and narrow a different way.
     */
    protected function requestedSector(Request $request): ?string
    {
        $sectorId = $request->query('sector_id');

        if (! $sectorId) {
            return null;
        }

        /*
         * Checked against what they actually cover, so a doctored query string
         * cannot widen anything. Belt and braces - the scope would return
         * nothing anyway - but a clear refusal beats a silently empty screen.
         */
        $mine = SectorContext::currentSectorIds($request->user());

        if ($mine !== null && ! in_array($sectorId, $mine, true)) {
            abort(403, 'That sector is not yours.');
        }

        return $sectorId;
    }

    protected function applySectorFilter(Builder $query, Request $request, string $via = 'customer'): void
    {
        $sectorId = $this->requestedSector($request);

        if (! $sectorId) {
            return;
        }

        $customers = DB::table('customers')->select('id')->where('sector_id', $sectorId);

        match ($via) {
            'sector' => $query->where($query->getModel()->qualifyColumn('sector_id'), $sectorId),
            'subscription' => $query->whereIn(
                $query->getModel()->qualifyColumn('subscription_id'),
                DB::table('subscriptions')->select('id')->whereIn('customer_id', $customers),
            ),
            default => $query->whereIn($query->getModel()->qualifyColumn('customer_id'), $customers),
        };
    }
}
