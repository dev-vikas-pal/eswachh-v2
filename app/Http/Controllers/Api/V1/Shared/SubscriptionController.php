<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Support\Http\FiltersBySector;
use App\Support\Http\RestrictsToOwnRecords;
use App\Support\Http\SortsLists;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Listing and reading subscriptions.
 *
 * Note what is missing: any mention of branch. The global scope has already
 * limited the query, so a franchise owner cannot see another branch's
 * subscriptions even if this controller forgot to think about it. That is the
 * point of scoping at the model.
 */
class SubscriptionController extends Controller
{
    use RestrictsToOwnRecords;
    use FiltersBySector;
    use SortsLists;

    private const SORTABLE = [
        'car' => 'vehicle_id',
        'renews' => 'period_end',
        'status' => 'status',
        'amount' => 'amount_paise',
        'created' => 'created_at',
    ];

    /** Kept in step with the front end's table component. */
    private const MAX_PER_PAGE = 100;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('view.subscription');

        $filters = $request->validate([
            'filter.status' => ['sometimes', 'string'],
            'filter.expired' => ['sometimes', 'boolean'],
            // Running and not yet due. Derived like expired, and needed so the
            // dashboard's "In date" tile can open a list that matches its own
            // number - a tile that links to a different figure is worse than
            // one that does not link at all.
            'filter.current' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.cleaner_id' => ['sometimes', 'uuid'],
            // v1's filters, which are how the office narrows a morning's work.
            'filter.sector_id' => ['sometimes', 'uuid'],
            'filter.package_id' => ['sometimes', 'uuid'],
            'filter.unassigned' => ['sometimes', 'boolean'],
            'filter.renew_from' => ['sometimes', 'date'],
            'filter.renew_to' => ['sometimes', 'date', 'after_or_equal:filter.renew_from'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $filter = $filters['filter'] ?? [];

        // 'duration' is loaded for the renewal timing, which needs to know how
        // long a term is before it can say what renewing now would do to the
        // dates. Eager, because working it out per row is twenty-five queries.
        $query = Subscription::query()->with(['vehicle.cleaner', 'customer', 'package', 'duration', 'lastPayment']);

        /*
         * One row per car: the period it is on now.
         *
         * A plan is a chain of periods - each renewal writes a new one and ends
         * the last, which is what gives every period its own price, dates and
         * invoice. Listing all of them showed the same car three times over,
         * two of them finished, and read as duplicate records rather than as
         * history.
         *
         * The earlier periods are still there and still reachable through
         * History on the row. Asked for by status, they come back too - "show
         * me what has ended" is a real question and this must not answer it
         * with nothing.
         */
        if (empty($filter['status']) && empty($filter['expired']) && empty($filter['current'])) {
            $query->whereNot('status', SubscriptionStatus::Ended);
        }

        // A customer holds view.subscription so they can look at their own
        // plan. The ability says nothing about whose rows they are, and the
        // branch scope does not narrow it either - every other customer of
        // this franchise is inside their branch.
        $this->restrictToOwnRecords($query, $request);

        // The sector a customer lives in, not the branch that owns the plan:
        // one franchise covers several sectors and works them separately.
        if (! empty($filter['sector_id'])) {
            $query->whereHas('customer', fn ($c) => $c->where('sector_id', $filter['sector_id']));
        }

        if (! empty($filter['package_id'])) {
            $query->where('package_id', $filter['package_id']);
        }

        // Cars nobody is cleaning. The list that should be empty every morning.
        if (! empty($filter['unassigned'])) {
            $query->whereHas('vehicle', fn ($v) => $v->whereNull('assigned_cleaner_id'));
        }

        if (! empty($filter['renew_from'])) {
            $query->whereDate('period_end', '>=', $filter['renew_from']);
        }

        if (! empty($filter['renew_to'])) {
            $query->whereDate('period_end', '<=', $filter['renew_to']);
        }

        if (! empty($filter['status'])) {
            $query->where('status', SubscriptionStatus::from($filter['status']));
        }

        // Expired is derived, never stored, so it is a scope rather than a
        // status value. The same is true of its opposite.
        if (! empty($filter['expired'])) {
            $query->expired();
        }

        if (! empty($filter['current'])) {
            $query->current();
        }

        if (! empty($filter['cleaner_id'])) {
            $query->whereHas('vehicle', fn ($q) => $q->where('assigned_cleaner_id', $filter['cleaner_id']));
        }

        if (! empty($filter['search'])) {
            $term = $filter['search'];
            $query->where(function ($q) use ($term) {
                $q->whereHas('vehicle', fn ($v) => $v->where('registration', 'like', "%{$term}%"))
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%"));
            });
        }

        // Whitelisted, like every other list: the column goes into SQL.
        // The sector picker in the top bar, the same parameter every other
        // list answers to.
        $this->applySectorFilter($query, $request, 'customer');

        $this->applySort($query, $request, self::SORTABLE, 'created');
        $query->orderByDesc('created_at');

        return SubscriptionResource::collection(
            $query->paginate($filters['per_page'] ?? 25)->withQueryString()
        );
    }

    public function show(Request $request, Subscription $subscription): SubscriptionResource
    {
        $this->authorize('view.subscription');

        // Route model binding resolves through the scoped query, so a
        // subscription in another branch is simply not found - a 404 rather
        // than a 403, which does not confirm that it exists.
        //
        // The branch is not enough for a customer, though: their neighbours are
        // in the same branch. Somebody else's plan is a 404 for them too.
        abort_unless($this->ownsRecord($request, $subscription->customer_id), 404);

        return new SubscriptionResource(
            // lastPayment too: the edit form shows the payment mode, date and
            // reference beside the plan, as v1's order form did.
            $subscription->load([
                'vehicle.cleaner', 'vehicle.model', 'customer',
                'package', 'serviceType', 'duration', 'lastPayment',
            ])
        );
    }
}
