<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Customer;
use App\Models\Sector;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the SPA needs to draw itself, in one call.
 *
 * Who you are, what you may do, and which branches you can look at. The front
 * end renders its navigation and branch selector from this rather than making
 * a call per question.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('branch');

        return response()->json([
            'data' => new UserResource($user),
            'sectors' => $this->sectorsFor($user),
            // What a customer currently has, so the public site can decide
            // which of its buttons are worth showing them.
            'standing' => $this->standingOf($user),
        ]);
    }

    /**
     * What this person has running, if they are a customer.
     *
     * Two booleans rather than the plans themselves: the public header only
     * needs to know whether to offer topping up cloths, and sending a
     * customer's whole account to draw a navigation bar would be waste.
     *
     * @return array<string, bool>
     */
    private function standingOf(User $user): array
    {
        if ($user->role !== UserRole::Customer) {
            return ['has_active_plan' => false, 'has_cloth_service' => false];
        }

        $plans = Subscription::query()
            ->whereIn('customer_id', Customer::query()->where('user_id', $user->id)->select('id'))
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Hold])
            ->get(['cloth_service']);

        return [
            'has_active_plan' => $plans->isNotEmpty(),
            // A plan without the cloth service cannot top up, so offering it
            // would take them to a page that only says no.
            'has_cloth_service' => $plans->contains(fn ($p) => (bool) $p->cloth_service),
        ];
    }

    /**
     * The territory this person covers.
     *
     * An administrator gets every sector; everyone else gets the ones assigned
     * to them. The list is only for rendering the filter - the server checks
     * the sector on every request, so a doctored list buys nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectorsFor($user): array
    {
        /*
         * Resolved before the unscoped block, not inside it.
         *
         * currentSectorIds() answers null - meaning "ask nothing of this query"
         * - while the scope is lifted, which is correct for the scope and wrong
         * here: read inside withoutScope it turned every franchise user's own
         * territory into an empty list.
         */
        $mine = $user->seesAllSectors() ? null : (SectorContext::currentSectorIds($user) ?? []);

        return SectorContext::withoutScope(function () use ($mine) {
            $query = Sector::query()->where('status', true)->orderBy('name');

            if ($mine !== null) {
                $query->whereIn('id', $mine);
            }

            return $query->get(['id', 'name'])->map(fn (Sector $sector) => [
                'id' => $sector->id,
                'name' => $sector->name,
            ])->all();
        });
    }
}
