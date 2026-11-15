<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Domain\Auth\PhoneCodes;
use App\Domain\Billing\StartPayment;
use App\Domain\Pricing\PriceBook;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\City;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\Society;
use App\Models\State;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Signing up from the public site.
 *
 * Nobody is authenticated here, so this is the most exposed write in the
 * system. Three things hold it up:
 *
 *  - the mobile number is proved with a code before anything is created, which
 *    is what v1 did and what stops the form being used to fill the database
 *    with plans for numbers that do not exist;
 *  - the price is worked out from the masters, never read from the request. v1
 *    took `final_price` from its own quote endpoint and then trusted the total
 *    that came back with the form;
 *  - nothing is marked paid. The plan is created pending and a payment is
 *    opened; only the verified gateway callback moves it.
 *
 * Branch scoping is switched off deliberately in the few places it has to be:
 * there is no signed in user to derive a branch from, so the sector the
 * customer picked decides it, and the sector has to be readable to ask.
 */
class SignupController extends Controller
{
    public function __construct(
        private PhoneCodes $codes,
        private PriceBook $book,
        private StartPayment $starter,
    ) {}

    /**
     * Send a code to the number on the form.
     *
     * Unlike the sign in version this says plainly when a number is already
     * registered - it has to, because the alternative is letting somebody fill
     * the form in twice and only finding out at the payment step. It is not a
     * disclosure the sign in form avoids either: anybody can discover the same
     * thing by trying to sign in.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],

            /*
             * Sent so the answers that would refuse this signup can be given
             * now, before a message is spent on it.
             *
             * They used to be checked only when the form was submitted, which
             * is after the code has been sent, typed and read back - so a car
             * number already on the books was reported at the payment step,
             * with the whole form filled in and nothing to do but start again.
             *
             * Optional, because the same endpoint serves "send it again", when
             * the form has not changed and there is nothing new to check.
             */
            'registration' => ['sometimes', 'nullable', 'string', 'max:20'],
            'sector_id' => ['sometimes', 'nullable', 'string', 'exists:sectors,id'],
        ]);

        $phone = PhoneCodes::normalise($data['phone']);

        // Somebody who got as far as the payment window and closed it. Their
        // own number and their own car are not "already registered" to them.
        $unfinished = $this->unfinishedSignup($phone);

        if ($registration = $data['registration'] ?? null) {
            $this->refuseIfCarTaken(
                strtoupper((string) preg_replace('/\s+/', '', $registration)),
                $unfinished['vehicle']->id ?? null,
            );
        }

        if ($sectorId = $data['sector_id'] ?? null) {
            $this->place(['sector_id' => $sectorId]);
        }

        if (strlen($phone) !== 10) {
            throw ValidationException::withMessages(['phone' => 'That does not look like a mobile number.']);
        }

        // Room for a couple of resends, as on the sign in form.
        foreach (['signup:phone:'.$phone => 5, 'signup:ip:'.$request->ip() => 15] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages([
                    'phone' => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.',
                ])->status(429);
            }

            RateLimiter::hit($key, 600);
        }

        if (! $unfinished && $this->accountFor($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'That number is already registered. Sign in instead, or use the renewal page.',
            ]);
        }

        $this->codes->issue($phone, PhoneCodes::SIGNUP, $request->ip());

        return response()->json([
            'message' => 'A code is on its way.',
            'expires_in' => $this->codes->lifetimeSeconds(),
        ]);
    }

    /**
     * Create the customer, the car and the plan, and open a payment.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'size:6'],
            'email' => ['nullable', 'email', 'max:191'],

            'registration' => ['required', 'string', 'max:20'],
            'vehicle_model_id' => ['required', 'string', 'exists:vehicle_models,id'],

            'package_id' => ['required', 'string', 'exists:packages,id'],
            'service_type_id' => ['required', 'string', 'exists:service_types,id'],
            'duration_id' => ['required', 'string', 'exists:durations,id'],
            'cloth_bundle_id' => ['nullable', 'string', 'exists:cloth_bundles,id'],

            'state_id' => ['nullable', 'string', 'exists:states,id'],
            'city_id' => ['nullable', 'string', 'exists:cities,id'],
            'area_id' => ['nullable', 'string', 'exists:areas,id'],
            'sector_id' => ['required', 'string', 'exists:sectors,id'],
            'society_id' => ['nullable', 'string', 'exists:societies,id'],
            'house_no' => ['required', 'string', 'max:100'],
            'preferred_time' => ['nullable', 'date_format:H:i'],
        ]);

        $phone = PhoneCodes::normalise($data['phone']);

        /*
         * Checked, not spent.
         *
         * Everything below can still refuse this signup - a car number already
         * on the books, a sector taken off sale - and a code burned by one of
         * those leaves the customer retyping a code that has silently stopped
         * working, with nothing on screen to say why. It is spent inside the
         * transaction, once nothing else can fail.
         */
        $verified = $this->codes->check($phone, $data['code'], PhoneCodes::SIGNUP);

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid. Ask for a new one.',
            ]);
        }

        $registration = strtoupper((string) preg_replace('/\s+/', '', $data['registration']));

        /*
         * Coming back to a signup that was never paid for.
         *
         * The first attempt creates the account, the car and a pending plan
         * before the payment window opens, so that an abandoned checkout leaves
         * something for the office to chase. It also left the customer with
         * nowhere to go: closing the gateway and trying again was refused as
         * "already registered" by the very records their first try had made,
         * and the renewal page would not have them because nothing was paid.
         *
         * They have just proved the number again with a fresh code, so the
         * unpaid record is theirs to finish. It is brought up to date with
         * what the form says now and a new payment is opened on it.
         */
        $unfinished = $this->unfinishedSignup($phone);

        $this->refuseIfTaken($phone, $registration, $unfinished);

        $place = $this->place($data);

        /*
         * The form no longer offers cloths while the service is off, but the
         * form is not what enforces it - a stale page or a hand-made request
         * would otherwise still sell one.
         */
        if (! empty($data['cloth_bundle_id']) && ! SiteSettings::get('cloth_service_enabled')) {
            throw ValidationException::withMessages([
                'cloth_bundle_id' => 'The cloth ironing service is not available at the moment.',
            ]);
        }

        $quote = $this->quote($data);

        return DB::transaction(function () use ($data, $phone, $registration, $quote, $verified, $place, $unfinished) {
            // Nothing left that can refuse them, so the code is spent now.
            $this->codes->spend($verified);

            if ($unfinished) {
                $subscription = $this->bringUpToDate($unfinished, $data, $registration, $quote, $place);

                $result = SectorContext::withoutScope(fn () => $this->starter->forSubscription($subscription));

                return response()->json([
                    'data' => $result['checkout'],
                    'quote' => $quote->toArray(),
                    'subscription_id' => $subscription->id,
                ], 201);
            }

            $account = User::create([
                // A hint only; visibility comes from the customer's sector.
                'branch_id' => null,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $phone,
                'role' => UserRole::Customer,
                /*
                 * A random password nobody is told. They sign in with a code to
                 * this number, which is the one thing they have already proved.
                 * v1 generated a six character password and mailed it.
                 */
                'password' => Hash::make(Str::random(40)),
                'status' => true,
            ]);

            $customer = Customer::create([
                // A hint only; visibility comes from the customer's sector.
                'branch_id' => null,
                'user_id' => $account->id,
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                // From the sector, not the form: one chain, always complete.
                'state_id' => $place['state_id'],
                'city_id' => $place['city_id'],
                'area_id' => $place['area_id'],
                'sector_id' => $place['sector_id'],
                'society_id' => $place['society_id'],
                'house_no' => $data['house_no'],
                'preferred_time' => $data['preferred_time'] ?? null,
                'status' => true,
            ]);

            $vehicle = Vehicle::create([
                // A hint only; visibility comes from the customer's sector.
                'branch_id' => null,
                'customer_id' => $customer->id,
                'vehicle_model_id' => $data['vehicle_model_id'],
                'registration' => $registration,
                'status' => true,
            ]);

            $start = Carbon::today();

            $subscription = Subscription::create([
                // A hint only; visibility comes from the customer's sector.
                'branch_id' => null,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'package_id' => $data['package_id'],
                'service_type_id' => $data['service_type_id'],
                'duration_id' => $data['duration_id'],
                'sequence' => 1,
                'period_start' => $start,
                'period_end' => $start->copy()->addMonths($quote->months),
                // Pending until money arrives. Nothing on this path marks a
                // plan paid - only the verified callback does that.
                'status' => SubscriptionStatus::Pending,
                'amount_paise' => $quote->totalPaise,
                'paid_amount_paise' => 0,
                'cloth_service' => ! empty($data['cloth_bundle_id']),
                'cloth_bundle_id' => $data['cloth_bundle_id'] ?? null,
                'cloth_balance' => 0,
            ]);

            /*
             * Opened before the customer sees a payment window, so somebody who
             * abandons checkout still leaves a record to chase rather than
             * vanishing.
             */
            $result = $this->starter->forSubscription($subscription);

            return response()->json([
                'data' => $result['checkout'],
                'quote' => $quote->toArray(),
                'subscription_id' => $subscription->id,
            ], 201);
        });
    }

    // --------------------------------------------------------------- private

    /**
     * @param  array<string, mixed>  $data
     */
    private function quote(array $data)
    {
        try {
            /*
             * Scopes off because nobody is signed in, not because of anything
             * to do with branches: the masters a price is built from - package,
             * cleaning type, duration, car size - are one list for the whole
             * business and are not branch scoped at all. The society surcharge
             * is the only part that varies by where they live, and that comes
             * from the society they picked.
             */
            return SectorContext::withoutScope(fn () => $this->book->quote(
                $data['vehicle_model_id'],
                $data['package_id'],
                $data['service_type_id'],
                $data['duration_id'],
                $data['society_id'] ?? null,
                $data['cloth_bundle_id'] ?? null,
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['duration_id' => $e->getMessage()]);
        }
    }

    /**
     * The signup this number started and never paid for, if that is all it has.
     *
     * Deliberately narrow. One car, one plan, that plan still pending and no
     * money ever taken: the exact shape the public form leaves behind when the
     * payment window is closed. Anybody with a paid plan, a second car or a
     * plan the office has since moved on is a customer, and is sent to sign in
     * like any other.
     *
     * @return array{user: User, customer: Customer, vehicle: Vehicle, subscription: Subscription}|null
     */
    private function unfinishedSignup(string $phone): ?array
    {
        return SectorContext::withoutScope(function () use ($phone) {
            $user = $this->accountFor($phone);

            if (! $user || $user->role !== UserRole::Customer) {
                return null;
            }

            $customer = Customer::query()->where('user_id', $user->id)->first();

            if (! $customer) {
                return null;
            }

            $plans = Subscription::query()->where('customer_id', $customer->id)->get();
            $vehicles = Vehicle::query()->where('customer_id', $customer->id)->get();

            if ($plans->count() !== 1
                || $vehicles->count() !== 1
                || $plans->first()->status !== SubscriptionStatus::Pending) {
                return null;
            }

            $paid = Payment::query()
                ->where('customer_id', $customer->id)
                ->where('status', PaymentStatus::Captured)
                ->exists();

            return $paid ? null : [
                'user' => $user,
                'customer' => $customer,
                'vehicle' => $vehicles->first(),
                'subscription' => $plans->first(),
            ];
        });
    }

    /**
     * Rewrite an unfinished signup with what the form says now.
     *
     * Everything, not only what changed: the customer may have corrected a car
     * number, moved the address or picked a different plan before trying
     * again, and the price has been worked out afresh from those choices.
     *
     * Payments left open by the earlier attempt are closed, so the office's
     * list of abandoned signups shows one attempt to chase rather than one per
     * click.
     *
     * @param  array{user: User, customer: Customer, vehicle: Vehicle, subscription: Subscription}  $unfinished
     * @param  array<string, mixed>  $data
     * @param  array{state_id: string, city_id: string, area_id: string, sector_id: string, society_id: ?string}  $place
     */
    private function bringUpToDate(array $unfinished, array $data, string $registration, $quote, array $place): Subscription
    {
        return SectorContext::withoutScope(function () use ($unfinished, $data, $registration, $quote, $place) {
            $unfinished['user']->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
            ]);

            $unfinished['customer']->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'state_id' => $place['state_id'],
                'city_id' => $place['city_id'],
                'area_id' => $place['area_id'],
                'sector_id' => $place['sector_id'],
                'society_id' => $place['society_id'],
                'house_no' => $data['house_no'],
                'preferred_time' => $data['preferred_time'] ?? null,
            ]);

            $unfinished['vehicle']->update([
                'vehicle_model_id' => $data['vehicle_model_id'],
                'registration' => $registration,
            ]);

            $start = Carbon::today();
            $subscription = $unfinished['subscription'];

            $subscription->update([
                'package_id' => $data['package_id'],
                'service_type_id' => $data['service_type_id'],
                'duration_id' => $data['duration_id'],
                'period_start' => $start,
                'period_end' => $start->copy()->addMonths($quote->months),
                'amount_paise' => $quote->totalPaise,
                'cloth_service' => ! empty($data['cloth_bundle_id']),
                'cloth_bundle_id' => $data['cloth_bundle_id'] ?? null,
            ]);

            Payment::query()
                ->where('subscription_id', $subscription->id)
                ->where('status', PaymentStatus::Initiated)
                ->get()
                ->each(fn (Payment $payment) => $this->starter->abandon(
                    $payment,
                    'Replaced when the customer came back to finish signing up.',
                ));

            return $subscription;
        });
    }

    /**
     * @param  array{vehicle: Vehicle}|null  $unfinished  their own unpaid signup, which is not "taken"
     */
    private function refuseIfTaken(string $phone, string $registration, ?array $unfinished = null): void
    {
        if (! $unfinished && $this->accountFor($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'That number is already registered. Sign in instead.',
            ]);
        }

        $this->refuseIfCarTaken($registration, $unfinished['vehicle']->id ?? null);
    }

    /**
     * Asked twice: once before the code is sent, once before anything is
     * written. The early answer saves the customer a wasted round trip; the
     * late one is what actually guarantees it, because the form could have
     * changed in between.
     */
    private function refuseIfCarTaken(string $registration, ?string $exceptVehicleId = null): void
    {
        // Scopes off: a car already on another sector's books is still taken,
        // and finding that out at the payment step is far worse than here.
        $taken = Vehicle::withoutGlobalScopes()
            ->where('registration', $registration)
            ->whereNull('deleted_at')
            // Their own car, from a signup they are coming back to finish.
            ->when($exceptVehicleId, fn ($q) => $q->whereKeyNot($exceptVehicleId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'registration' => 'That car number is already registered. Use the renewal page instead.',
            ]);
        }
    }

    /**
     * Where the car is kept, checked as one chain and filled in from the sector.
     *
     * An active sector is one the business services. Nobody has to be assigned
     * to it yet - the head office may run it directly, and staff are given it
     * afterwards on the People screen. What must hold is the masters
     * themselves: the sector, its area, city and state all active and not
     * withdrawn, and anything the form sent belonging to that same chain. A
     * society from the next sector over would otherwise put its surcharge on
     * the bill and the wrong address on the round.
     *
     * The state, city and area are taken from the sector rather than trusted
     * from the form, so a customer record is never left half an address.
     *
     * @param  array<string, mixed>  $data
     * @return array{state_id: string, city_id: string, area_id: string, sector_id: string, society_id: ?string}
     */
    private function place(array $data): array
    {
        return SectorContext::withoutScope(function () use ($data) {
            $refuse = function (string $field, string $message): never {
                throw ValidationException::withMessages([$field => $message]);
            };

            $sector = Sector::query()->where('status', true)->find($data['sector_id'] ?? null);

            if (! $sector) {
                $refuse('sector_id', 'We do not service that sector at the moment. Please choose another, or call the office.');
            }

            $area = Area::query()->where('status', true)->find($sector->area_id);
            $city = $area ? City::query()->where('status', true)->find($area->city_id) : null;
            $state = $city ? State::query()->where('status', true)->find($city->state_id) : null;

            // A sector whose area, city or state has been switched off or
            // withdrawn is not on sale, whatever the sector row itself says.
            if (! $area || ! $city || ! $state) {
                $refuse('sector_id', 'That sector is not available at the moment. Please call the office.');
            }

            $chain = ['state_id' => $state->id, 'city_id' => $city->id, 'area_id' => $area->id];

            foreach ($chain as $field => $id) {
                if (! empty($data[$field]) && $data[$field] !== $id) {
                    $refuse($field, 'That does not match the sector chosen. Please choose the address again.');
                }
            }

            $societyId = $data['society_id'] ?? null;

            if ($societyId && ! Society::query()
                ->where('status', true)
                ->where('sector_id', $sector->id)
                ->whereKey($societyId)
                ->exists()) {
                $refuse('society_id', 'That society is not in the sector chosen.');
            }

            return $chain + ['sector_id' => $sector->id, 'society_id' => $societyId ?: null];
        });
    }

    private function accountFor(string $phone): ?User
    {
        return User::query()
            ->where(fn ($q) => $q->where('phone', $phone)
                ->orWhere('phone', '0'.$phone)
                ->orWhere('phone', '91'.$phone)
                ->orWhere('phone', '+91'.$phone))
            ->first();
    }
}
