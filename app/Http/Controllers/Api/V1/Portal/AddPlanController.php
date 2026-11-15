<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\Billing\StartPayment;
use App\Domain\Pricing\PriceBook;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A second car for somebody who already has an account.
 *
 * A household with two cars buys two plans, and v1 let them. v2 sent them to
 * the public signup form, which cannot serve them at all: that form proves a
 * mobile number with a code and then refuses numbers it already knows - so an
 * existing customer was told their own number was taken.
 *
 * Here they are already signed in, which is a stronger proof than a code sent
 * to a phone, so there is no code step. Everything about who they are comes
 * from the session: no customer id, no address, no phone in the request, and
 * therefore nothing to point at anybody else.
 */
class AddPlanController extends Controller
{
    public function __construct(
        private PriceBook $book,
        private StartPayment $starter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration' => ['required', 'string', 'max:20'],
            'vehicle_model_id' => ['required', 'string', 'exists:vehicle_models,id'],
            'package_id' => ['required', 'string', 'exists:packages,id'],
            'service_type_id' => ['required', 'string', 'exists:service_types,id'],
            'duration_id' => ['required', 'string', 'exists:durations,id'],
            'cloth_bundle_id' => ['nullable', 'string', 'exists:cloth_bundles,id'],
        ]);

        $customer = $this->customerFor($request);

        $registration = strtoupper((string) preg_replace('/\s+/', '', $data['registration']));

        /*
         * Scopes off for the clash check only. A car already on another
         * franchise's books is still taken, and finding that out at the payment
         * step is far worse than finding it out here.
         */
        $existing = Vehicle::withoutGlobalScopes()
            ->where('registration', $registration)
            ->whereNull('deleted_at')
            ->first();

        if ($existing && $existing->customer_id !== $customer->id) {
            throw ValidationException::withMessages([
                'registration' => 'That car number is already registered. Please call the office.',
            ]);
        }

        $latest = $existing?->currentSubscription;

        // Their own car, added again while it has a plan that is running or
        // paused: renew it rather than starting a second plan on the same
        // registration.
        if ($latest && in_array($latest->status, [SubscriptionStatus::Active, SubscriptionStatus::Hold], true)) {
            throw ValidationException::withMessages([
                'registration' => 'You already have a running plan for that car. Renew it instead.',
            ]);
        }

        /*
         * A plan for this car that was started here and never paid for.
         *
         * Adding the car creates it and a pending plan before the payment
         * window opens. Closing that window and trying again used to be refused
         * as "you already have a running plan" - by a plan that had never run -
         * with nothing to renew and no way to add the car a second time. The
         * unpaid plan is theirs to finish, so it is brought up to date with
         * what they chose this time and a new payment is opened on it.
         *
         * A plan that has ended is neither of these: the car simply gets its
         * next period below, as it always should have.
         */
        $unpaid = $latest && $latest->status === SubscriptionStatus::Pending ? $latest : null;

        $quote = $this->quote($data, $customer);

        return DB::transaction(function () use ($data, $customer, $registration, $existing, $quote, $unpaid) {
            $vehicle = $existing ?? Vehicle::create([
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'vehicle_model_id' => $data['vehicle_model_id'],
                'registration' => $registration,
                'status' => true,
            ]);

            $start = Carbon::today();

            if ($unpaid) {
                $vehicle->update(['vehicle_model_id' => $data['vehicle_model_id']]);

                $unpaid->update([
                    'package_id' => $data['package_id'],
                    'service_type_id' => $data['service_type_id'],
                    'duration_id' => $data['duration_id'],
                    'period_start' => $start,
                    'period_end' => $start->copy()->addMonths($quote->months),
                    'amount_paise' => $quote->totalPaise,
                    'cloth_service' => ! empty($data['cloth_bundle_id']),
                    'cloth_bundle_id' => $data['cloth_bundle_id'] ?? null,
                ]);

                // One open attempt to chase, not one per click.
                Payment::query()
                    ->where('subscription_id', $unpaid->id)
                    ->where('status', PaymentStatus::Initiated)
                    ->get()
                    ->each(fn (Payment $payment) => $this->starter->abandon(
                        $payment,
                        'Replaced when the customer tried the payment again.',
                    ));

                $result = $this->starter->forSubscription($unpaid);

                return response()->json([
                    'data' => $result['checkout'],
                    'quote' => $quote->toArray(),
                    'subscription_id' => $unpaid->id,
                ], 201);
            }

            $subscription = Subscription::create([
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'package_id' => $data['package_id'],
                'service_type_id' => $data['service_type_id'],
                'duration_id' => $data['duration_id'],
                // The next period for this car, so a car that had a plan before
                // keeps a readable history rather than restarting at one.
                'sequence' => (int) Subscription::withoutGlobalScopes()
                    ->where('vehicle_id', $vehicle->id)->max('sequence') + 1,
                'period_start' => $start,
                'period_end' => $start->copy()->addMonths($quote->months),
                // Pending until money arrives, exactly as a signup is.
                'status' => SubscriptionStatus::Pending,
                'amount_paise' => $quote->totalPaise,
                'paid_amount_paise' => 0,
                'cloth_service' => ! empty($data['cloth_bundle_id']),
                'cloth_bundle_id' => $data['cloth_bundle_id'] ?? null,
                'cloth_balance' => 0,
            ]);

            $result = $this->starter->forSubscription($subscription);

            return response()->json([
                'data' => $result['checkout'],
                'quote' => $quote->toArray(),
                'subscription_id' => $subscription->id,
            ], 201);
        });
    }

    // --------------------------------------------------------------- private

    private function customerFor(Request $request): Customer
    {
        abort_unless($request->user()->role === UserRole::Customer, 403);

        return Customer::query()
            ->where('user_id', $request->user()->id)
            ->firstOr(fn () => abort(422, 'There is no customer record for this account.'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function quote(array $data, Customer $customer)
    {
        try {
            /*
             * The same price book the public form uses, given the customer's own
             * society so any surcharge where they live is applied. Nothing about
             * the amount comes from the request.
             */
            return SectorContext::withoutScope(fn () => $this->book->quote(
                $data['vehicle_model_id'],
                $data['package_id'],
                $data['service_type_id'],
                $data['duration_id'],
                $customer->society_id,
                $data['cloth_bundle_id'] ?? null,
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['duration_id' => $e->getMessage()]);
        }
    }
}
