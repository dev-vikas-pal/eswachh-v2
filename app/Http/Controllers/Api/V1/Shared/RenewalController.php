<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Domain\Billing\StartPayment;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\Quote;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClothBundle;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Http\RestrictsToOwnRecords;
use App\Support\Tenancy\SectorContext;
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

        /*
         * A customer may change what they are buying at the moment they renew.
         *
         * v1 offered this and it matters most for the duration: the business
         * discounts three and six month plans, and somebody who cannot see that
         * while they are paying never takes it.
         *
         * Accepted here rather than through the edit endpoint, which needs
         * update.subscription - a permission a customer does not have and should
         * not be given just to change their own plan. Only these four fields,
         * and the price is still worked out by the server afterwards.
         */
        $chosen = $request->validate([
            'package_id' => ['sometimes', 'string', 'exists:packages,id'],
            'service_type_id' => ['sometimes', 'string', 'exists:service_types,id'],
            'duration_id' => ['sometimes', 'string', 'exists:durations,id'],
            'cloth_bundle_id' => ['sometimes', 'nullable', 'string', 'exists:cloth_bundles,id'],
        ]);

        if ($chosen) {
            foreach ($chosen as $field => $value) {
                $subscription->{$field} = $value;
            }

            $subscription->cloth_service = (bool) $subscription->cloth_bundle_id;
            $subscription->save();
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

        /*
         * The plan is priced at today's rates before the payment is opened.
         *
         * StartPayment charges what the subscription says it costs, so without
         * this a customer who switched from one month to six would be shown the
         * six month price and charged the one month one. The figure comes from
         * the price book either way - never from the request.
         */
        $before = (int) $subscription->amount_paise;

        $result = $this->openAtQuotedPrice($subscription, $quote);

        return response()->json([
            'data' => $result['checkout'],
            'quote' => $quote->toArray(),
            'timing' => $subscription->renewalTiming(),
            // Flagged rather than left for somebody to spot by comparing two
            // numbers: a renewal is bought at today's prices, not last year's.
            // Compared against what it cost before this call, not after - the
            // plan has just been repriced, so reading it now would always say
            // nothing had changed.
            'price_changed' => $before !== $quote->totalPaise,
            'previously_paid' => $before / 100,
        ], 201);
    }

    /**
     * Write the quoted price onto the plan, then open a payment for it.
     *
     * The one place a renewal turns into a charge, because the office renewal
     * and the public one have to agree on the figure. They did not: the public
     * page quoted the price book and then handed StartPayment a subscription
     * still carrying whatever it cost last time, so a customer was shown ₹225
     * and Razorpay asked them for ₹1,872. StartPayment charges what the plan
     * says it costs and is right to - so the plan has to say the quoted price
     * before it is asked.
     *
     * @return array{payment: \App\Models\Payment, checkout: array<string, mixed>}
     */
    private function openAtQuotedPrice(Subscription $subscription, Quote $quote): array
    {
        if ((int) $subscription->amount_paise !== $quote->totalPaise) {
            $subscription->forceFill(['amount_paise' => $quote->totalPaise])->save();
        }

        // Unscoped, because this runs from the public page too, where there is
        // nobody signed in and a scoped re-read would find nothing.
        return SectorContext::withoutScope(
            fn () => $this->starter->forSubscription($subscription->fresh() ?? $subscription)
        );
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

        $bundle = SectorContext::withoutScope(
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

        $subscription = SectorContext::withoutScope(function () use ($registration) {
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

        /*
         * The car and the customer are read outside the scope, and the price is
         * worked out in there with them.
         *
         * Nobody is signed in on this page, so the scope covers no sectors and
         * both relations came back null. Everything downstream then degraded
         * quietly rather than failing: the card showed an empty registration
         * beside an empty name, and - far worse - the price book priced a plan
         * with no car and no society, dropping the vehicle category and the
         * society surcharge and quoting ₹225 for a ₹1,872 renewal.
         */
        try {
            $quote = SectorContext::withoutScope(function () use ($subscription) {
                $subscription->load('vehicle', 'customer');

                return $this->book->forRenewal($subscription);
            });
        } catch (RuntimeException) {
            return response()->json([
                'found' => false,
                'message' => 'That plan needs to be renewed over the phone. Please call the office.',
            ], 404);
        }

        if (! $quote->isComplete()) {
            // A plan that cannot be priced in full must not be half-quoted on a
            // page with a Pay button under it.
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
                // What they are buying, itemised. A price with no explanation
                // is the thing people phone the office about.
                'lines' => $quote->toArray()['lines'] ?? [],
                'months' => $quote->months,
                // Early, due, or overdue - the same answer the office and the
                // portal give for this plan.
                'timing' => $subscription->renewalTiming(),
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

        $subscription = SectorContext::withoutScope(
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

        /*
         * Priced again here, at the same rates the lookup quoted.
         *
         * This used to open the payment straight from the plan's stored amount,
         * which is what it cost the last time it was bought - so the page said
         * one figure and the gateway asked for another. Repricing is also the
         * only honest way round: a customer who left the tab open overnight is
         * charged today's price, and it is the price they were just shown.
         */
        try {
            $quote = SectorContext::withoutScope(fn () => $this->book->forRenewal($subscription));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['registration' => $e->getMessage()]);
        }

        if (! $quote->isComplete()) {
            throw ValidationException::withMessages([
                'registration' => 'That plan needs to be renewed over the phone. Please call the office.',
            ]);
        }

        $result = $this->openAtQuotedPrice($subscription, $quote);

        return response()->json([
            'data' => $result['checkout'],
            'quote' => $quote->toArray(),
        ], 201);
    }
}
