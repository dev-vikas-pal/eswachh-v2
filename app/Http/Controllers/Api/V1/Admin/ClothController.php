<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClothMovement;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Cloths out at the laundry.
 *
 * The two screens the requirements document asks for. Pickup is a car at a
 * time - the cleaner is standing next to it. Delivery is a list, because the
 * cleaner comes back with a bag of everybody's and works down it.
 *
 * Ordered by society for the same reason: the round is walked society by
 * society, and a list in any other order means walking it twice.
 */
class ClothController extends Controller
{
    /**
     * Find a car to collect from, by its number.
     *
     * Returns the balance as well as the car, because the question the cleaner
     * is about to be asked - how many are we taking - only makes sense next to
     * how many the customer has left.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('record.cloth');

        $data = $request->validate([
            'registration' => ['required', 'string', 'max:20'],
        ]);

        $registration = strtoupper((string) preg_replace('/\s+/', '', $data['registration']));

        // Branch scoped: a cleaner cannot collect from another franchise's car.
        $vehicle = Vehicle::query()
            ->with(['customer.society', 'currentSubscription'])
            ->where('registration', $registration)
            ->first();

        if (! $vehicle) {
            return response()->json([
                'message' => 'No car with that number on this branch\'s books.',
            ], 404);
        }

        $plan = $vehicle->currentSubscription;

        if (! $plan || ! $plan->cloth_service) {
            return response()->json([
                'message' => 'That car has no cloth service on its plan.',
            ], 422);
        }

        $today = Carbon::today();

        return response()->json([
            'data' => [
                'vehicle_id' => $vehicle->id,
                'registration' => $vehicle->registration,
                'customer' => $vehicle->customer?->name,
                'house_no' => $vehicle->customer?->house_no,
                'society' => $vehicle->customer?->society?->name,
                'balance' => (int) $plan->cloth_balance,

                /*
                 * What was already recorded today, if anything. The screen shows
                 * it so a cleaner who taps twice sees a correction rather than
                 * wondering whether the first one saved.
                 */
                'collected_today' => (int) (ClothMovement::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->pickups()->on($today)->value('cloth_count') ?? 0),
            ],
        ]);
    }

    /**
     * Everything collected and not yet returned.
     *
     * The delivery round. A pickup is outstanding until a delivery is recorded
     * for the same car on the same day or later - so a bag collected on Monday
     * and returned on Wednesday stays on this list for two days, which is
     * exactly how long it is genuinely out.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('record.cloth');

        $filters = $request->validate([
            'society_id' => ['sometimes', 'string', 'exists:societies,id'],
            'mine' => ['sometimes', 'boolean'],
        ]);

        $pickups = ClothMovement::query()
            ->pickups()
            ->with(['vehicle.customer.society', 'cleaner:id,name'])
            /*
             * No delivery for the same car on or after the day it was taken.
             *
             * Written as a subquery on the table itself rather than through a
             * relation, because the condition compares two rows of the same
             * table - the delivery's date against this pickup's - and that is
             * not something a relation can express.
             */
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('cloth_movements as returned')
                    ->whereColumn('returned.vehicle_id', 'cloth_movements.vehicle_id')
                    ->where('returned.direction', ClothMovement::DELIVERY)
                    ->whereColumn('returned.moved_on', '>=', 'cloth_movements.moved_on')
                    ->whereNull('returned.deleted_at');
            })
            ->when($filters['mine'] ?? false, fn ($q) => $q->where('cleaner_id', $request->user()->id))
            ->when($filters['society_id'] ?? null, fn ($q, $id) => $q
                ->whereHas('vehicle.customer', fn ($c) => $c->where('society_id', $id)))
            ->orderBy('moved_on')
            ->get();

        /*
         * Grouped by society rather than sorted by it, so the screen can show a
         * heading per society and the cleaner can see at a glance how many
         * stops are left rather than counting rows.
         */
        $bySociety = $pickups
            ->groupBy(fn (ClothMovement $m) => $m->vehicle?->customer?->society?->name ?? 'No society')
            ->map(fn ($group, $society) => [
                'society' => $society,
                'total_cloths' => (int) $group->sum('cloth_count'),
                'cars' => $group->map(fn (ClothMovement $m) => [
                    'movement_id' => $m->id,
                    'vehicle_id' => $m->vehicle_id,
                    'registration' => $m->vehicle?->registration,
                    'customer' => $m->vehicle?->customer?->name,
                    'house_no' => $m->vehicle?->customer?->house_no,
                    'cloth_count' => (int) $m->cloth_count,
                    'collected_on' => $m->moved_on?->toDateString(),
                    'days_out' => $m->moved_on ? $m->moved_on->diffInDays(Carbon::today()) : 0,
                    'collected_by' => $m->cleaner?->name,
                ])->values(),
            ])
            ->sortKeys()
            ->values();

        return response()->json([
            'data' => $bySociety,
            'meta' => [
                'cars' => $pickups->count(),
                'cloths' => (int) $pickups->sum('cloth_count'),
            ],
        ]);
    }
}
