<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a customer sees when they sign in.
 *
 * v1 gave customers a login and a page showing their orders; this is the same
 * idea rebuilt. Everything is read from the customer records that carry the
 * signed in user's id, so there is no id in any of these routes to tamper with -
 * the question "whose?" is answered by the session and nothing else.
 *
 * The office's screens are not reused for this. A customer looking at "the
 * subscriptions list, filtered" would still be one forgotten filter away from
 * the whole branch, and would be shown columns - cleaner, branch, cost price -
 * that are the business's, not theirs.
 */
class PortalController extends Controller
{
    /**
     * Everything the portal's home page needs, in one call.
     */
    public function overview(Request $request): JsonResponse
    {
        $customers = $this->customersFor($request);

        if ($customers->isEmpty()) {
            /*
             * A login with no customer record behind it. This happens when an
             * account was made by hand, and it should read as "nothing here
             * yet" rather than as an error - there is nothing wrong, they just
             * have no plan.
             */
            return response()->json([
                'data' => [
                    'profile' => $this->profileFrom($request),
                    'vehicles' => [],
                    'plans' => [],
                    'totals' => ['active' => 0, 'due_soon' => 0, 'unpaid_paise' => 0],
                ],
            ]);
        }

        $ids = $customers->pluck('id');

        $plans = Subscription::query()
            ->whereIn('customer_id', $ids)
            ->with(['vehicle.model', 'vehicle.cleaner', 'package', 'serviceType', 'duration', 'lastPayment'])
            ->orderByDesc('period_end')
            ->get();

        return response()->json([
            'data' => [
                'profile' => $this->profileFrom($request, $customers->first()),
                'vehicles' => $customers->flatMap->vehicles->map(fn ($v) => [
                    'id' => $v->id,
                    'registration' => $v->registration,
                    'model' => $v->model?->name,
                ])->values(),
                'plans' => SubscriptionResource::collection($plans)->resolve(),
                'totals' => [
                    'active' => $plans->where('status', SubscriptionStatus::Active)->count(),
                    // Renewing in the next fortnight: the number that decides
                    // whether the page needs to nag.
                    'due_soon' => $plans->filter(fn (Subscription $s) => $s->status === SubscriptionStatus::Active
                        && $s->period_end
                        && $s->period_end->betweenIncluded(now()->startOfDay(), now()->addDays(14)->endOfDay()))->count(),
                    'unpaid_paise' => (int) $plans->sum(fn (Subscription $s) => max(0, $s->amount_paise - $s->paid_amount_paise)),
                ],
            ],
        ]);
    }

    /**
     * Their receipts.
     *
     * Only captured ones: a payment that was opened and abandoned is noise to
     * the person who abandoned it, and showing a "failed" row next to their
     * name reads as though something is wrong with their account.
     */
    public function payments(Request $request): JsonResponse
    {
        $ids = $this->customersFor($request)->pluck('id');

        $payments = Payment::query()
            ->whereIn('customer_id', $ids)
            ->where('status', PaymentStatus::Captured)
            ->with('subscription.vehicle')
            ->orderByDesc('paid_at')
            ->paginate(20);

        return response()->json([
            'data' => PaymentResource::collection($payments->items())->resolve(),
            'meta' => [
                'total' => $payments->total(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    /**
     * Correct their own details.
     *
     * A deliberately short list. Where somebody lives decides which franchise
     * services them and what the plan costs, so sector and society are the
     * office's to change, not something a customer can move themselves into on
     * a Sunday night. Name, phone, email and the doorstep detail are theirs.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $this->customersFor($request)->first();

        abort_unless((bool) $customer, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'house_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        $customer->update($data);

        // The name is on both records, and a customer who changes it once
        // should not have to wonder why the greeting still says the old one.
        if (isset($data['name'])) {
            $request->user()->forceFill(['name' => $data['name']])->save();
        }

        return response()->json(['data' => $this->profileFrom($request, $customer->fresh())]);
    }

    // --------------------------------------------------------------- private

    /**
     * The customer records behind this login.
     *
     * Usually one. A household that put two plans on one login has two, and
     * returning a collection means the portal shows both rather than silently
     * picking the first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Customer>
     */
    private function customersFor(Request $request)
    {
        abort_unless($request->user()->role === UserRole::Customer, 403);

        return Customer::query()
            ->where('user_id', $request->user()->id)
            ->with(['sector:id,name', 'society:id,name', 'vehicles.model:id,name'])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function profileFrom(Request $request, ?Customer $customer = null): array
    {
        $user = $request->user();

        return [
            'name' => $customer?->name ?? $user->name,
            'phone' => $customer?->phone ?? $user->phone,
            'email' => $customer?->email ?? $user->email,
            'house_no' => $customer?->house_no,
            'address' => $customer?->address,
            'preferred_time' => $customer?->preferred_time,
            'sector' => $customer?->sector?->name,
            'society' => $customer?->society?->name,
        ];
    }
}
