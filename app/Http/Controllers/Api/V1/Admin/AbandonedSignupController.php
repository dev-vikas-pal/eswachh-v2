<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Http\FiltersBySector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * People who started a plan and never finished paying.
 *
 * The signup flow deliberately writes the customer, the car and the plan before
 * the payment window opens, so somebody who closes it leaves a record rather
 * than vanishing. Until now nothing read those records: they sat as pending
 * plans nobody looked at, which is the same as vanishing but with the database
 * a little larger.
 *
 * This is the screen that makes them worth writing. Every row is somebody who
 * wanted the service, gave their number, and got as far as the payment page -
 * the warmest call the office will make all week.
 */
class AbandonedSignupController extends Controller
{
    use FiltersBySector;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('view.subscription');

        $filters = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $since = Carbon::today()->subDays($filters['days'] ?? 30);

        /*
         * Started from the payment, not the plan.
         *
         * A plan can be pending for reasons that are nobody's fault - the
         * office created it to be paid in cash on the round. What makes this
         * list actionable is that a payment was actually opened and never came
         * back, which is somebody who reached the gateway and stopped.
         */
        $query = Payment::query()
            ->with(['customer:id,name,phone,sector_id', 'customer.sector:id,name', 'subscription.vehicle:id,registration'])
            ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Failed])
            ->where('created_at', '>=', $since)
            /*
             * And nothing since. Somebody who abandoned one attempt and paid on
             * the second does not want a phone call about it.
             */
            ->whereDoesntHave('subscription', fn ($s) => $s->whereIn('status', [
                SubscriptionStatus::Active,
                SubscriptionStatus::Hold,
                SubscriptionStatus::Ended,
            ]))
            ->latest('created_at');

        $this->applySectorFilter($query, $request, 'sector');

        $rows = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => array_map(fn (Payment $p) => [
                'id' => $p->id,
                'name' => $p->customer?->name,
                // The whole point of the screen.
                'phone' => $p->customer?->phone,
                'sector' => $p->customer?->sector?->name,
                'car' => $p->subscription?->vehicle?->registration,
                'amount' => $p->amount_paise / 100,
                'status' => $p->status->value,
                // "Gave up two hours ago" and "gave up three weeks ago" are
                // different calls, so the age is on the row.
                'started_at' => $p->created_at?->toIso8601String(),
                'subscription_id' => $p->subscription_id,
            ], $rows->items()),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'since' => $since->toDateString(),
            ],
        ]);
    }
}
