<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every dashboard figure in one response.
 *
 * v1 issued six counts and two sums to draw this screen. One endpoint means
 * one round trip and one place where the definitions live, so the tiles cannot
 * drift apart from the reports.
 *
 * Nothing here filters by branch explicitly: the global scope has already done
 * it. Passing a branch the caller does not own is handled by resolveBranch.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('view.dashboard');

        $sectorIds = $this->resolveSectors($request);

        return SectorContext::forSectorsOrOwn($sectorIds, function () use ($request) {
            $today = Carbon::today();

            return response()->json([
                'data' => [
                    'subscriptions' => [
                        // Active includes periods past their renewal date: they
                        // are still being serviced until somebody acts.
                        'active' => Subscription::active()->count(),
                        'current' => Subscription::current()->count(),
                        'expired' => Subscription::expired()->count(),
                        'hold' => Subscription::onHold()->count(),
                        'unassigned' => Subscription::active()
                            ->whereHas('vehicle', fn ($q) => $q->whereNull('assigned_cleaner_id'))
                            ->count(),
                    ],
                    'people' => [
                        /*
                         * Counted from the customer record, not the login.
                         *
                         * A customer's territory is the sector on their address;
                         * their user account holds no sectors at all, so asking
                         * the users table which ones are "mine" has no answer.
                         * Customer is already scoped, so this needs no filter.
                         */
                        'customers' => Customer::query()->count(),
                        'cleaners' => User::query()
                            ->role(UserRole::Cleaner)
                            ->inSectors(SectorContext::currentSectorIds())
                            ->count(),
                    ],
                    'vehicles' => [
                        'total' => Vehicle::count(),
                    ],

                    /*
                     * The only part of this screen a date range means anything
                     * to. Everything above is a count of how things stand right
                     * now - "how many plans are active last Tuesday" is not a
                     * question the data can answer - so the filter is attached
                     * to this block alone rather than to the whole screen.
                     */
                    'period' => $this->periodFigures($request),

                    'as_at' => $today->toDateString(),
                ],
            ]);
        });
    }

    /**
     * What happened in the chosen period, as opposed to how things stand.
     *
     * Defaults to this month, which is the window somebody opening a dashboard
     * has in mind. Only captured payments count towards the money: an opened
     * checkout that nobody finished is not revenue.
     *
     * @return array<string, mixed>
     */
    private function periodFigures(Request $request): array
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($filters['from'])
            ? Carbon::parse($filters['from'])->startOfDay()
            : Carbon::today()->startOfMonth();

        $to = isset($filters['to'])
            ? Carbon::parse($filters['to'])->endOfDay()
            : Carbon::today()->endOfDay();

        $captured = Payment::query()->revenue()->between($from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue_paise' => (int) (clone $captured)->sum('amount_paise'),
            'payments' => (clone $captured)->count(),
            // Plans that began in this window, which is the growth figure the
            // revenue number on its own does not tell you.
            'new_plans' => Subscription::query()->whereBetween('period_start', [$from, $to])->count(),
        ];
    }

    /**
     * The branch being asked about, refusing anything the caller does not own.
     *
     * Null means "everything I am allowed to see", which for a franchise owner
     * is still only their own branch.
     */
    /**
     * Narrow the figures to one sector, when the screen asks for one.
     *
     * Null means "leave my own scope alone", which for a franchise user is
     * every sector they cover and for an administrator is everything.
     *
     * @return array<int, string>|null
     */
    private function resolveSectors(Request $request): ?array
    {
        $requested = $request->query('sector_id');

        if (! $requested) {
            return null;
        }

        $user = $request->user();

        /*
         * The requested sector is never trusted.
         *
         * Checked against what they actually cover rather than against
         * anything on the request, so asking for somebody else's sector is
         * refused outright instead of quietly widening the figures.
         */
        $mine = SectorContext::currentSectorIds($user);

        if ($mine !== null && ! in_array($requested, $mine, true)) {
            abort(403, 'That sector is not yours.');
        }

        return [$requested];
    }
}
