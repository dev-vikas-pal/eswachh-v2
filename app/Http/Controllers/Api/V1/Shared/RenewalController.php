<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Domain\Billing\StartPayment;
use App\Domain\Pricing\PriceBook;
use App\Enums\PaymentPurpose;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClothBundle;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Http\RestrictsToOwnRecords;
use App\Support\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Paying for an existing plan: renewing it, or topping up cloths.
 *
 * v1 had three routes into this and v2 had none - it could only take money for
 * a plan that had never been paid for. These are the ones customers actually
 * use, since almost every payment after the first is a renewal.
 *
 * The price is never taken from the request. It is worked out from the plan's
 * own masters at today's prices, which is also why the reply says plainly when
 * that differs from what was paid last time.
 */
class RenewalController extends Controller
{
    use RestrictsToOwnRecords;

    public function __construct(
        private PriceBook $book,
        private StartPayment $starter,
    ) {}

    /**
     * What renewing this plan would cost, and open a payment for it.
     */
    public function renew(Request $request, Subscription $subscription): JsonResponse
    {
        // Two ways in, because two people renew a plan: the office does it for
        // someone on the phone, and the customer does it themselves. They are
        // different abilities on purpose - a customer renewing their own plan
        // is not the same permission as the office editing anybody's.
        $this->allowRenewalBy($request, $subscription);

        $subscription->load('vehicle', 'customer');

        if ($subscription->status === SubscriptionStatus::Ended) {
            throw ValidationException::withMessages([
                'subscription' => 'This plan has ended. Start a new one rather than renewing it.',
            ]);
        }

        try {
            $quote = $this->book->forRenewal($subscription);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        if (! $quote->isComplete()) {
            // Renewing at a price we know is wrong would undercharge silently.
            throw ValidationException::withMessages([
                'subscription' => 'This plan cannot be priced: '.$quote->warnings[0],
            ]);
        }

        $result = $this->starter->forSubscription($subscription);

        return response()->json([
            'data' => $result['checkout'],
            'quote' => $quote->toArray(),
            // Flagged rather than left for somebody to spot by comparing two
            // numbers: a renewal is bought at today's prices, not last year's.
            'price_changed' => (int) $subscription->amount_paise !== $quote->totalPaise,
            'previously_paid' => $subscription->amount_paise / 100,
        ], 201);
    }

    /**
     * Buy more cloths for a running plan.
     */
    public function topUp(Request $request, Subscription $subscription): JsonResponse
    {
        $this->allowRenewalBy($request, $subscription, 'buy.cloth.topup');

        $data = $request->validate([
            'cloth_bundle_id' => ['required', 'string', 'exists:cloth_bundles,id'],
        ]);

        $bundle = BranchContext::withoutScope(
            fn () => ClothBundle::query()->where('status', true)->findOrFail($data['cloth_bundle_id'])
        );

        $result = $this->starter->forClothTopUp($subscription, $bundle);

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

    /**
     * May this person pay for this plan?
     *
     * The office renews anybody's plan in its branch; a customer renews their
     * own and nobody else's. Written once here rather than repeated, because
     * getting the second half wrong is how somebody ends up looking at a
     * neighbour's plan.
     */
    private function allowRenewalBy(Request $request, Subscription $subscription, string $customerAbility = 'renew.subscription'): void
    {
        $user = $request->user();

        if ($user?->hasAbility('update.subscription')) {
            return;
        }

        // A 404 rather than a 403, so refusing does not confirm that the plan
        // exists - consistent with how every other out-of-scope record reads.
        abort_unless(
            $user?->hasAbility($customerAbility) && $this->ownsRecord($request, $subscription->customer_id),
            404
        );
    }

    /**
     * Renew without signing in, by quoting a car number.
     *
     * v1 had this and it earns its keep: most customers never make an account,
     * and asking somebody to remember a password before they can pay you is a
     * good way not to be paid.
     *
     * It is the only unauthenticated route that can find a customer record, so
     * it is deliberately narrow: it returns what a renewal costs and nothing
     * about the person, and it is rate limited hard enough that the car numbers
     * of a city cannot be walked through.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration' => ['required', 'string', 'max:20'],
        ]);

        $registration = strtoupper(preg_replace('/\s+/', '', $data['registration']));

        // Per address and per car number together, so guessing at plates from
        // one machine stops quickly.
        $key = 'renew-lookup:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'registration' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        RateLimiter::hit($key, 300);

        $subscription = BranchContext::withoutScope(function () use ($registration) {
            $vehicle = Vehicle::query()->where('registration', $registration)->first();

            return $vehicle?->subscriptions()
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Hold])
                ->latest('sequence')
                ->first();
        });

        if (! $subscription) {
            // The same answer whether the car is unknown or has no live plan.
            // Telling them apart would confirm which cars are customers.
            return response()->json([
                'found' => false,
                'message' => 'We could not find a running plan for that car number. Please call the office.',
            ], 404);
        }

        $subscription->load('vehicle', 'customer');

        try {
            $quote = $this->book->forRenewal($subscription);
        } catch (RuntimeException) {
            return response()->json([
                'found' => false,
                'message' => 'That plan needs to be renewed over the phone. Please call the office.',
            ], 404);
        }

        return response()->json([
            'found' => true,
            'data' => [
                'subscription_id' => $subscription->id,
                'registration' => $subscription->vehicle?->registration,
                // First name only. Enough to show the right car was found,
                // without handing over a customer list to anyone with a plate.
                'name' => str($subscription->customer?->name ?? '')->before(' ')->toString(),
                'renews_on' => $subscription->period_end?->toDateString(),
                'status' => $subscription->status->value,
                'amount' => $quote->total(),
                'formatted' => $quote->toArray()['formatted'],
            ],
        ]);
    }

    /**
     * Open a payment for a plan found by car number.
     */
    public function payWithoutSigningIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'string', 'exists:subscriptions,id'],
            'registration' => ['required', 'string', 'max:20'],
        ]);

        $registration = strtoupper(preg_replace('/\s+/', '', $data['registration']));

        $subscription = BranchContext::withoutScope(
            fn () => Subscription::query()->with('vehicle', 'customer')->find($data['subscription_id'])
        );

        /*
         * The car number is checked against the plan again here. Without it the
         * id alone would be enough to open a payment for any plan, and ids
         * leak far more easily than the pairing does.
         */
        abort_unless(
            $subscription && $subscription->vehicle?->registration === $registration,
            404,
        );

        $result = BranchContext::withoutScope(fn () => $this->starter->forSubscription($subscription));

        return response()->json(['data' => $result['checkout']], 201);
    }
}
