<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Domain\Billing\StartPayment;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClothBundle;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Buying more cloths without signing in.
 *
 * One of the four things the requirements document puts on the home page:
 * quote a car number, pick a plan, pay, and the balance goes up. v1 had it and
 * it is the one cloth screen a customer ever sees.
 *
 * Two rules carried over from the renewal page, for the same reasons. The car
 * number and the plan id are both sent when paying, so an id on its own is not
 * enough to top up somebody else's car - ids leak far more easily than the
 * pairing of the two. And a car we do not know gets the same answer as one with
 * no cloth plan, so this cannot be used to find out who is a customer.
 */
class ClothTopUpController extends Controller
{
    public function __construct(private StartPayment $starter) {}

    /**
     * What this car's cloth balance is, and what may be bought.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration' => ['required', 'string', 'max:20'],
        ]);

        $registration = strtoupper((string) preg_replace('/\s+/', '', $data['registration']));

        // Hard limit: this is the only unauthenticated route that can find a
        // cloth balance, so the plates of a city must not be walkable.
        $key = 'cloth-lookup:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'registration' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        RateLimiter::hit($key, 300);

        $subscription = $this->planFor($registration);

        if (! $subscription) {
            /*
             * The document asks for this: an unknown car is sent to the signup
             * page rather than left at a dead end, because somebody typing a
             * car number into a top-up box is a customer either way.
             */
            return response()->json([
                'found' => false,
                'message' => 'We could not find a plan for that car number.',
                'subscribe_instead' => true,
            ], 404);
        }

        if (! $subscription->cloth_service) {
            return response()->json([
                'found' => false,
                'message' => 'That plan does not include the cloth service. Please call the office to add it.',
                'subscribe_instead' => false,
            ], 404);
        }

        return response()->json([
            'found' => true,
            'data' => [
                'subscription_id' => $subscription->id,
                'registration' => $subscription->vehicle?->registration,
                // A first name only, as on the renewal page: enough to show the
                // right car was found, not enough to be worth harvesting.
                'name' => strtok((string) $subscription->customer?->name, ' '),
                'balance' => (int) $subscription->cloth_balance,
                'bundles' => $this->bundles(),
            ],
        ]);
    }

    /**
     * Open a payment for a cloth bundle.
     *
     * Nothing is credited here. The bundle's own price is charged and the
     * cloths are added by the verified gateway callback, exactly as they are
     * for a signed in customer - v1 took the money for twenty two top-ups and
     * credited none of them.
     */
    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'string', 'exists:subscriptions,id'],
            'registration' => ['required', 'string', 'max:20'],
            'cloth_bundle_id' => ['required', 'string', 'exists:cloth_bundles,id'],
        ]);

        $registration = strtoupper((string) preg_replace('/\s+/', '', $data['registration']));

        $subscription = SectorContext::withoutScope(
            fn () => Subscription::query()->with('vehicle', 'customer')->find($data['subscription_id'])
        );

        // The pairing is the authorisation. An id alone will not do.
        if (! $subscription || $subscription->vehicle?->registration !== $registration) {
            return response()->json(['message' => 'That car number does not match the plan.'], 404);
        }

        if (! $subscription->cloth_service) {
            return response()->json(['message' => 'That plan does not include the cloth service.'], 422);
        }

        $bundle = SectorContext::withoutScope(
            fn () => ClothBundle::query()->where('status', true)->find($data['cloth_bundle_id'])
        );

        if (! $bundle) {
            return response()->json(['message' => 'That cloth plan is no longer on sale.'], 422);
        }

        $result = SectorContext::withoutScope(
            fn () => $this->starter->forClothTopUp($subscription, $bundle)
        );

        return response()->json([
            'data' => $result['checkout'],
            'bundle' => [
                'name' => $bundle->name,
                'cloths' => $bundle->cloth_count,
                'price' => $bundle->price_paise / 100,
            ],
            'balance_after' => $subscription->cloth_balance + $bundle->cloth_count,
        ], 201);
    }

    // --------------------------------------------------------------- private

    private function planFor(string $registration): ?Subscription
    {
        return SectorContext::withoutScope(function () use ($registration) {
            $vehicle = Vehicle::query()->where('registration', $registration)->first();

            return $vehicle?->subscriptions()
                ->with('vehicle', 'customer')
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Hold])
                ->latest('sequence')
                ->first();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bundles(): array
    {
        return SectorContext::withoutScope(
            fn () => ClothBundle::query()->where('status', true)->orderBy('cloth_count')->get()
        )->map(fn (ClothBundle $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'cloths' => (int) $b->cloth_count,
            'price' => $b->price_paise / 100,
        ])->all();
    }
}
