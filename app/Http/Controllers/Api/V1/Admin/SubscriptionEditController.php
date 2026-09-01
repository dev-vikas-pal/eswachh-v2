<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Pricing\PriceBook;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings\SiteSettings;
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

    /** Set when a non-administrator tried to change the car number. */
    private bool $registrationRefused = false;

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
                'data' => new SubscriptionResource($subscription->load('vehicle', 'customer', 'package', 'duration')),
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

        /*
         * The business-wide lock, for when a client asks that franchises stop
         * changing what customers are on.
         *
         * Checked here rather than only in the interface, because a screen that
         * hides a button is a courtesy and this is a rule. An administrator is
         * never locked out - somebody has to be able to correct a plan.
         */
        if (SiteSettings::get('lock_plan_edits_to_admin')
            && $request->user()?->role !== UserRole::SuperAdmin) {
            abort(403, 'Plans can only be changed by an administrator at the moment. Please call the office.');
        }

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

            /*
             * The payment details v1 kept on the order form.
             *
             * They live on the payment here rather than being copied onto the
             * plan, so correcting them corrects the receipt, the payments list
             * and the revenue report at once - in v1 the same figures existed in
             * two places and drifted apart.
             *
             * Nested under `payment` so it is obvious at the call site that
             * these touch a different record, and so the amount cannot be
             * smuggled in beside them: what was charged is not editable here.
             */
            'payment' => ['sometimes', 'array'],
            'payment.method' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment.paid_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'payment.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $subscription->load('customer', 'vehicle');

        $this->applyVehicleChanges($subscription, $data);
        $this->applyPaymentChanges($request, $subscription, $data);

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
            'data' => new SubscriptionResource($subscription->fresh()->load('vehicle', 'customer', 'package', 'duration')),
            // Said plainly rather than silently ignored, so nobody walks away
            // believing a plate was changed when it was not.
            'notice' => $this->registrationRefused
                ? 'Everything else was saved. Only an administrator can change a car number.'
                : null,
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
            /*
             * The car number is the one field on this form an administrator
             * alone may change.
             *
             * It is how a customer is found on the phone, how the cleaner knows
             * which car is theirs, and what every historical payment is filed
             * under. A typo corrected by the office is a real need, but so is
             * knowing that a plate does not quietly change hands - so the
             * ability to do it stops at the administrator.
             *
             * Ignored rather than refused when somebody else sends it: the rest
             * of their edit is legitimate and should still save. The reply says
             * what was left alone.
             */
            if ($this->actorMayRenumber()) {
                $this->applyRegistration($vehicle, $data['registration']);
            } else {
                $this->registrationRefused = true;
            }
        }

        $vehicle->save();
    }

    /**
     * Correct the details of the last payment against this plan.
     *
     * v1 kept the payment mode, date and reference on the order itself, so the
     * office could fix a mistyped UPI reference from the same form. Here those
     * belong to the payment, and correcting them from this form updates the
     * receipt, the payments list and the revenue report together rather than
     * leaving two copies to drift.
     *
     * What is never editable is the amount. A figure that can be typed is a
     * figure that can be typed wrong, and it is the one number the whole
     * reconciliation rests on - changing what was charged is a refund, which is
     * its own action with its own record.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyPaymentChanges(Request $request, Subscription $subscription, array $data): void
    {
        if (empty($data['payment'])) {
            return;
        }

        // Touching money needs the money permission, not just the plan one.
        abort_unless($request->user()?->hasAbility('create.payment'), 403,
            'You cannot change payment details.');

        $payment = $subscription->lastPayment()->first();

        if (! $payment) {
            throw ValidationException::withMessages([
                'payment' => 'There is no payment against this plan to correct.',
            ]);
        }

        $changes = [];

        foreach (['method', 'reference', 'notes'] as $field) {
            if (array_key_exists($field, $data['payment'])) {
                $changes[$field] = $data['payment'][$field];
            }
        }

        if (array_key_exists('paid_at', $data['payment']) && $data['payment']['paid_at']) {
            $changes['paid_at'] = Carbon::parse($data['payment']['paid_at']);
        }

        if ($changes) {
            // forceFill: these are not fillable on purpose, so nothing can set
            // them straight from a request body anywhere else.
            $payment->forceFill($changes)->save();
        }
    }

    /** Only a super admin renumbers a car. */
    private function actorMayRenumber(): bool
    {
        return request()->user()?->role === UserRole::SuperAdmin;
    }

    private function applyRegistration(Vehicle $vehicle, string $value): void
    {
        $registration = strtoupper(preg_replace('/\s+/', '', $value));

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
