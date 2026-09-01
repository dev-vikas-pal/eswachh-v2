<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Domain\Pricing\PriceBook;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What a plan costs.
 *
 * The only place a price comes from. Nothing in the system accepts an amount
 * from a request body: v1 did, checking only that it was at least one rupee,
 * which meant a customer could pay ₹1 for any subscription.
 */
class PricingController extends Controller
{
    public function __construct(private PriceBook $book) {}

    /**
     * Price a combination of masters.
     *
     * Used by the subscribe form as the customer fills it in, and by the office
     * when setting a plan up. The reply is itemised so "why is it this much?"
     * is answerable on the screen rather than by asking somebody.
     */
    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate([
            'vehicle_model_id' => ['nullable', 'string', 'exists:vehicle_models,id'],
            'package_id' => ['nullable', 'string', 'exists:packages,id'],
            'service_type_id' => ['nullable', 'string', 'exists:service_types,id'],
            'duration_id' => ['required', 'string', 'exists:durations,id'],
            'society_id' => ['nullable', 'string', 'exists:societies,id'],
            'cloth_bundle_id' => ['nullable', 'string', 'exists:cloth_bundles,id'],
        ]);

        try {
            $quote = $this->book->quote(
                $input['vehicle_model_id'] ?? null,
                $input['package_id'] ?? null,
                $input['service_type_id'] ?? null,
                $input['duration_id'],
                $input['society_id'] ?? null,
                $input['cloth_bundle_id'] ?? null,
            );
        } catch (RuntimeException $e) {
            // A price list that cannot produce a price is a configuration
            // problem, and the message says which one.
            throw ValidationException::withMessages(['duration_id' => $e->getMessage()]);
        }

        return response()->json(['data' => $quote->toArray()]);
    }

    /**
     * What this subscription would cost to renew, at today's prices.
     *
     * Deliberately re-priced rather than repeating what was paid last time: a
     * renewal is bought at today's prices, and if a master has changed the
     * customer should be told the real figure before they pay.
     */
    public function renewal(Subscription $subscription): JsonResponse
    {
        try {
            $quote = $this->book->forRenewal($subscription->load('vehicle', 'customer'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        return response()->json([
            'data' => array_merge($quote->toArray(), [
                'previously_paid_paise' => (int) $subscription->amount_paise,
                // Flagged rather than left for someone to spot by comparing two
                // numbers on screen.
                'changed' => (int) $subscription->amount_paise !== $quote->totalPaise,
                // Early, due or overdue, from the same place every other screen
                // reads it.
                'timing' => $subscription->renewalTiming(),
            ]),
        ]);
    }
}
