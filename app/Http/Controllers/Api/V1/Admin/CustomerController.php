<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sector;
use App\Support\Http\FiltersBySector;
use App\Support\Http\SortsLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customers.
 *
 * Deliberately a different screen from staff, backed by a different table. A
 * cleaner is a login and a branch; a customer is an address, a car and a plan.
 * Creating one through the staff form would produce an account with no address
 * and no vehicle - a record that looks like a customer and cannot be serviced.
 *
 * Branch scoping comes from the model's global scope, so a franchise owner sees
 * their own and there is no parameter to widen it with.
 */
class CustomerController extends Controller
{
    use FiltersBySector;
    use SortsLists;

    /** Columns this screen may be ordered by. */
    private const SORTABLE = [
        'name' => 'name',
        'phone' => 'phone',
        'sector' => 'sector_id',
        'vehicles' => 'vehicles_count',
        'active' => 'active_subscriptions_count',
        'created' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('view.customer');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'sector_id' => ['sometimes', 'string', 'exists:sectors,id'],
            'with_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Customer::query()
            ->with('sector:id,name', 'society:id,name')
            ->withCount([
                'vehicles',
                'subscriptions as active_subscriptions_count' => fn ($q) => $q->where('status', SubscriptionStatus::Active),
            ]);

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                // Searching by car number is how the office actually finds
                // somebody: the customer on the phone quotes their plate.
                ->orWhereHas('vehicles', fn ($v) => $v->where('registration', 'like', '%'.str_replace(' ', '', $search).'%')));
        }

        if ($sector = $filters['sector_id'] ?? null) {
            $query->where('sector_id', $sector);
        }

        if ($filters['with_active'] ?? false) {
            $query->whereHas('subscriptions', fn ($q) => $q->where('status', SubscriptionStatus::Active));
        }

        // The sector picker in the top bar.
        $this->applySectorFilter($query, $request, 'sector');

        $this->applySort($query, $request, self::SORTABLE, 'name');

        $customers = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => array_map(fn (Customer $c) => $this->present($c), $customers->items()),
            'meta' => [
                'total' => $customers->total(),
                'per_page' => $customers->perPage(),
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
            ] + $this->sortMeta($request, self::SORTABLE, 'name'),
        ]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view.customer');

        $customer->load([
            'sector:id,name', 'society:id,name',
            'vehicles.cleaner:id,name',
            'vehicles.currentSubscription',
        ]);

        return response()->json([
            'data' => array_merge($this->present($customer), [
                'address' => $customer->address,
                'preferred_time' => $customer->preferred_time,
                /*
                 * The whole address, so the edit form can prefill itself.
                 *
                 * Deliberately here and not on the list: state and city are of
                 * no use in a table, and sending them for every one of two
                 * hundred rows to serve the one being edited is waste. The form
                 * fetches the customer it is editing.
                 */
                'state_id' => $customer->state_id,
                'city_id' => $customer->city_id,
                'area_id' => $customer->area_id,
                'vehicles' => $customer->vehicles->map(fn ($v) => [
                    'id' => $v->id,
                    'registration' => $v->registration,
                    'cleaner' => $v->cleaner?->name,
                    'subscription' => $v->currentSubscription ? [
                        'id' => $v->currentSubscription->id,
                        'status' => $v->currentSubscription->status->value,
                        'period_end' => $v->currentSubscription->period_end?->toDateString(),
                    ] : null,
                ]),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create.customer');

        $data = $this->validated($request);

        // The branch comes from the sector: the franchise that services that
        // sector is the one that owns the customer. Taking it from the request
        // would let a customer be filed against a branch that does not go there.
        $customer = Customer::create($data + ['branch_id' => $this->branchForSector($data['sector_id'] ?? null)]);

        return response()->json(['data' => $this->present($customer->fresh())], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update.customer');

        $data = $this->validated($request, $customer);

        if (array_key_exists('sector_id', $data)) {
            // Moving a customer to another sector moves them to whichever
            // franchise covers it, which is the point of the move.
            $data['branch_id'] = $this->branchForSector($data['sector_id']) ?? $customer->branch_id;
        }

        $customer->update($data);

        return response()->json(['data' => $this->present($customer->fresh())]);
    }

    // --------------------------------------------------------------- private

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $existing = null): array
    {
        $sometimes = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:120'],
            /*
             * Unique across live customers.
             *
             * Two records for one phone number is how a customer ends up with
             * two plans on one car and gets chased twice. Scoped to the whole
             * business rather than the branch: the same person moving between
             * sectors is still the same person.
             */
            'phone' => [
                $sometimes, 'string', 'max:20',
                Rule::unique('customers', 'phone')
                    ->ignore($existing?->id)
                    ->whereNull('deleted_at'),
            ],
            'email' => [
                'nullable', 'email', 'max:191',
                Rule::unique('customers', 'email')
                    ->ignore($existing?->id)
                    ->whereNull('deleted_at'),
            ],
            'state_id' => ['nullable', 'string', 'exists:states,id'],
            'city_id' => ['nullable', 'string', 'exists:cities,id'],
            'area_id' => ['nullable', 'string', 'exists:areas,id'],
            /*
             * Required, not optional.
             *
             * The sector is what decides who can see this customer. Without one
             * they belong to no territory and are invisible to every franchise
             * user and cleaner - a record that looks created and cannot be
             * serviced, with nothing on any screen to explain why.
             */
            'sector_id' => [$sometimes, 'required', 'string', 'exists:sectors,id'],

            /*
             * And the society has to sit in that sector.
             *
             * Not for visibility - nothing reads the society for that any more -
             * but for money: the society carries the monthly surcharge, so one
             * borrowed from another sector charges the wrong rate every month
             * for as long as nobody notices.
             */
            'society_id' => [
                'nullable', 'string',
                Rule::exists('societies', 'id')->where(
                    fn ($q) => $q->where('sector_id', $request->input('sector_id', $existing?->sector_id))
                ),
            ],
            'house_no' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'preferred_time' => ['nullable', 'date_format:H:i'],
            'status' => ['sometimes', 'boolean'],
        ]);
    }

    private function branchForSector(?string $sectorId): ?string
    {
        if (! $sectorId) {
            return null;
        }

        return Sector::withoutGlobalScopes()->whereKey($sectorId)->value('branch_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'house_no' => $customer->house_no,
            'sector' => $customer->sector?->name,
            'sector_id' => $customer->sector_id,
            'society' => $customer->society?->name,
            'society_id' => $customer->society_id,
            'status' => (bool) $customer->status,
            'vehicles_count' => $customer->vehicles_count ?? null,
            // What the office actually wants to know at a glance: are they a
            // paying customer right now.
            'active_subscriptions_count' => $customer->active_subscriptions_count ?? null,
            'branch_id' => $customer->branch_id,
        ];
    }
}
