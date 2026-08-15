<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Pricing\PriceBook;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creating and editing a plan from the office.
 *
 * The rule that matters: the amount is never taken from the request. A price is
 * worked out from the masters that were chosen, by the same engine the public
 * quote uses, so the office cannot type a figure and neither can a client. v1
 * accepted whatever price arrived and checked only that it was at least one
 * rupee.
 *
 * What the office CAN do is record that a different amount was agreed - a
 * discount, a goodwill adjustment - and that is a separate, named field with a
 * reason, not a quiet overwrite of the price.
 */
class SubscriptionEditController extends Controller
{
    public function __construct(private PriceBook $book) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create.subscription');

        $data = $request->validate([
            'customer_id' => ['required', 'string', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],
            'registration' => ['required_without:vehicle_id', 'nullable', 'string', 'max:20'],
            'vehicle_model_id' => ['nullable', 'string', 'exists:vehicle_models,id'],
            'package_id' => ['required', 'string', 'exists:packages,id'],
            'service_type_id' => ['required', 'string', 'exists:service_types,id'],
            'duration_id' => ['required', 'string', 'exists:durations,id'],
            'cloth_bundle_id' => ['nullable', 'string', 'exists:cloth_bundles,id'],
            'period_start' => ['sometimes', 'date'],
            'agreed_amount_paise' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'agreed_reason' => ['required_with:agreed_amount_paise', 'nullable', 'string', 'max:255'],
        ]);

        // Scoped find: another branch's customer is a 404 here.
        $customer = Customer::query()->findOrFail($data['customer_id']);

        return DB::transaction(function () use ($data, $customer) {
            $vehicle = $this->resolveVehicle($data, $customer);
            $quote = $this->quoteFor($data, $customer, $vehicle);

            $months = $quote->months;
            $start = isset($data['period_start']) ? Carbon::parse($data['period_start']) : Carbon::today();

            $subscription = Subscription::create([
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'package_id' => $data['package_id'],
                'service_type_id' => $data['service_type_id'],
                'duration_id' => $data['duration_id'],
                // The next period for this car, so history stays readable.
                'sequence' => (int) Subscription::query()
                    ->where('vehicle_id', $vehicle->id)->max('sequence') + 1,
                'period_start' => $start,
                'period_end' => $start->copy()->addMonths($months),
                // Pending until money arrives. Nothing here marks a plan paid.
                'status' => \App\Enums\SubscriptionStatus::Pending,
                'amount_paise' => $this->finalAmount($data, $quote->totalPaise),
                'paid_amount_paise' => 0,
                'cloth_service' => ! empty($data['cloth_bundle_id']),
                'cloth_bundle_id' => $data['cloth_bundle_id'] ?? null,
                'cloth_balance' => 0,
            ]);

            return response()->json([
                'data' => new SubscriptionResource($subscription->load('vehicle', 'customer', 'package')),
                'quote' => $quote->toArray(),
            ], 201);
        });
    }

    /**
     * Change a plan.
     *
     * Only the things that can legitimately change mid-term. The period and the
     * status are not among them: extending a period is what a payment does, and
     * pausing is its own endpoint that records why.
     */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('update.subscription');

        $data = $request->validate([
            'package_id' => ['sometimes', 'string', 'exists:packages,id'],
            'service_type_id' => ['sometimes', 'string', 'exists:service_types,id'],
            'duration_id' => ['sometimes', 'string', 'exists:durations,id'],
            'cloth_bundle_id' => ['sometimes', 'nullable', 'string', 'exists:cloth_bundles,id'],
            'period_start' => ['sometimes', 'date'],
            'period_end' => ['sometimes', 'date', 'after:today'],
            'reprice' => ['sometimes', 'boolean'],
            'agreed_amount_paise' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'agreed_reason' => ['required_with:agreed_amount_paise', 'nullable', 'string', 'max:255'],

            // v1 edited these on the same form, so they are accepted here too.
            'registration' => ['sometimes', 'string', 'max:20'],
            'vehicle_model_id' => ['sometimes', 'nullable', 'string', 'exists:vehicle_models,id'],
            'assigned_cleaner_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
        ]);

        $subscription->load('customer', 'vehicle');

        $this->applyVehicleChanges($subscription, $data);

        if (isset($data['period_start'])) {
            $subscription->period_start = Carbon::parse($data['period_start']);
        }

        if (isset($data['status'])) {
            $status = SubscriptionStatus::from($data['status']);

            $subscription->status = $status;
            // Cleared when it comes off hold, so a restarted plan does not look
            // paused in every report that reads held_at.
            $subscription->held_at = $status === SubscriptionStatus::Hold ? now() : null;
            $subscription->ended_at = $status === SubscriptionStatus::Ended ? now() : null;
        }

        foreach (['package_id', 'service_type_id', 'duration_id', 'cloth_bundle_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $subscription->{$field} = $data[$field];
            }
        }

        $subscription->cloth_service = (bool) $subscription->cloth_bundle_id;

        /*
         * Re-pricing is asked for, never automatic. Changing a package on a
         * running plan should not silently change what the customer owes
         * without whoever did it seeing the new figure first.
         */
        if ($data['reprice'] ?? false) {
            try {
                $quote = $this->book->forRenewal($subscription);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['duration_id' => $e->getMessage()]);
            }

            $subscription->amount_paise = $this->finalAmount($data, $quote->totalPaise);
        } elseif (array_key_exists('agreed_amount_paise', $data) && $data['agreed_amount_paise'] !== null) {
            $subscription->amount_paise = $data['agreed_amount_paise'];
        }

        // Correcting a wrong end date is a real need; moving it forward for
        // free is not, so it is only ever allowed to be brought closer.
        if (isset($data['period_end'])) {
            $requested = Carbon::parse($data['period_end']);

            if ($subscription->period_end && $requested->greaterThan($subscription->period_end)) {
                throw ValidationException::withMessages([
                    'period_end' => 'A period can only be shortened here. Extending one is what a payment does.',
                ]);
            }

            $subscription->period_end = $requested;
        }

        $subscription->save();

        return response()->json([
            'data' => new SubscriptionResource($subscription->fresh()->load('vehicle', 'customer', 'package')),
        ]);
    }

    // --------------------------------------------------------------- private

    /**
     * The car fields v1 kept on the order form.
     *
     * They live on the vehicle, not the plan, so editing them here writes
     * through to the car - which is what somebody correcting a mistyped
     * registration actually means.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyVehicleChanges(Subscription $subscription, array $data): void
    {
        $vehicle = $subscription->vehicle;

        if (! $vehicle) {
            return;
        }

        if (array_key_exists('vehicle_model_id', $data)) {
            $vehicle->vehicle_model_id = $data['vehicle_model_id'];
        }

        if (array_key_exists('assigned_cleaner_id', $data)) {
            $cleaner = $data['assigned_cleaner_id']
                ? User::query()->visible()->find($data['assigned_cleaner_id'])
                : null;

            if ($data['assigned_cleaner_id'] && (! $cleaner || $cleaner->branch_id !== $vehicle->branch_id)) {
                throw ValidationException::withMessages([
                    'assigned_cleaner_id' => 'That cleaner cannot be given this car.',
                ]);
            }

            $vehicle->assigned_cleaner_id = $cleaner?->id;
        }

        if (isset($data['registration'])) {
            $registration = strtoupper(preg_replace('/\s+/', '', $data['registration']));

            $clash = Vehicle::withoutGlobalScopes()
                ->where('registration', $registration)
                ->whereNull('deleted_at')
                ->whereKeyNot($vehicle->id)
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'registration' => 'That car number is already registered to another customer.',
                ]);
            }

            $vehicle->registration = $registration;
        }

        $vehicle->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveVehicle(array $data, Customer $customer): Vehicle
    {
        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);

            if ($vehicle->customer_id !== $customer->id) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'That car belongs to a different customer.',
                ]);
            }

            return $vehicle;
        }

        $registration = strtoupper(preg_replace('/\s+/', '', (string) $data['registration']));

        $existing = Vehicle::query()->where('registration', $registration)->first();

        if ($existing && $existing->customer_id !== $customer->id) {
            // A car cannot be on two customers' books at once, and finding out
            // at the payment step is far worse than finding out here.
            throw ValidationException::withMessages([
                'registration' => 'That car number is already registered to another customer.',
            ]);
        }

        return $existing ?? Vehicle::create([
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'vehicle_model_id' => $data['vehicle_model_id'] ?? null,
            'registration' => $registration,
            'status' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function quoteFor(array $data, Customer $customer, Vehicle $vehicle)
    {
        try {
            return $this->book->quote(
                $data['vehicle_model_id'] ?? $vehicle->vehicle_model_id,
                $data['package_id'],
                $data['service_type_id'],
                $data['duration_id'],
                $customer->society_id,
                $data['cloth_bundle_id'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['duration_id' => $e->getMessage()]);
        }
    }

    /**
     * The quoted price, unless a different one was agreed and explained.
     *
     * @param  array<string, mixed>  $data
     */
    private function finalAmount(array $data, int $quoted): int
    {
        if (! array_key_exists('agreed_amount_paise', $data) || $data['agreed_amount_paise'] === null) {
            return $quoted;
        }

        // A reason is required by the validation above, so an agreed price is
        // always attributable rather than an unexplained number in a column.
        return (int) $data['agreed_amount_paise'];
    }
}
