<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
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

        $branchId = $this->resolveBranch($request);

        return BranchContext::forBranchOrAll($branchId, function () {
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
                        'customers' => User::query()
                            ->role(UserRole::Customer)
                            ->inBranch(BranchContext::currentBranchId())
                            ->count(),
                        'cleaners' => User::query()
                            ->role(UserRole::Cleaner)
                            ->inBranch(BranchContext::currentBranchId())
                            ->count(),
                    ],
                    'vehicles' => [
                        'total' => Vehicle::count(),
                    ],
                    'as_at' => $today->toDateString(),
                ],
            ]);
        });
    }

    /**
     * The branch being asked about, refusing anything the caller does not own.
     *
     * Null means "everything I am allowed to see", which for a franchise owner
     * is still only their own branch.
     */
    private function resolveBranch(Request $request): ?string
    {
        $requested = $request->query('branch_id');

        if (! $requested) {
            return null;
        }

        $user = $request->user();

        // The requested branch is never trusted. A franchise owner asking for
        // somebody else's branch is refused outright.
        if (! $user->seesAllBranches() && $requested !== $user->branch_id) {
            abort(403, 'That branch is not yours.');
        }

        return $requested;
    }
}
