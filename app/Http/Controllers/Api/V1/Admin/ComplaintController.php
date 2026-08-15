<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Support\ComplaintWorkflow;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\User;
use App\Support\Http\SortsLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ComplaintController extends Controller
{
    use SortsLists;

    /**
     * Columns a person may re-order by.
     *
     * 'queue' is not among them: the default order below is a raw expression,
     * and it stays the default. Asking for one of these replaces it.
     */
    private const SORTABLE = [
        'reference' => 'reference',
        'due' => 'due_at',
        'category' => 'category',
        'priority' => 'priority',
        'raised' => 'created_at',
    ];

    public function __construct(private ComplaintWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(ComplaintStatus::class)],
            'category' => ['sometimes', Rule::enum(ComplaintCategory::class)],
            'overdue' => ['sometimes', 'boolean'],
            'mine' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Complaint::query()->with(['customer', 'vehicle', 'assignee']);

        // A customer only ever sees their own, whatever else they ask for.
        // Enforced here rather than trusted to the client.
        if ($request->user()->role === UserRole::Customer) {
            $query->whereIn('customer_id', Customer::query()
                ->where('user_id', $request->user()->id)
                ->select('id'));
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($category = $filters['category'] ?? null) {
            $query->where('category', $category);
        }

        if ($filters['overdue'] ?? false) {
            $query->overdue();
        }

        if ($filters['mine'] ?? false) {
            $query->where('assigned_to', $request->user()->id);
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        /*
         * Live complaints first, oldest first within that, so the queue reads
         * top to bottom as the order to work in. Sorting by created_at alone
         * would bury an overdue complaint under this morning's new ones.
         */
        $query->orderByRaw("FIELD(status, 'open', 'assigned', 'resolved', 'closed')")
            ->orderBy('due_at')
            ->orderBy('created_at');

        $this->applySort($query, $request, self::SORTABLE, 'queue');

        $liveCount = (clone $query)->live()->count();
        $overdueCount = (clone $query)->overdue()->count();

        $complaints = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => ComplaintResource::collection($complaints->items()),
            'meta' => [
                'total' => $complaints->total(),
                'per_page' => $complaints->perPage(),
                'current_page' => $complaints->currentPage(),
                'last_page' => $complaints->lastPage(),
                'live' => $liveCount,
                // The number that should drive somebody's morning.
                'overdue' => $overdueCount,
            ] + $this->sortMeta($request, self::SORTABLE, 'queue'),
        ]);
    }

    public function show(Request $request, Complaint $complaint): ComplaintResource
    {
        $this->assertVisible($request, $complaint);

        return new ComplaintResource(
            $complaint->load(['customer', 'vehicle', 'assignee', 'events.actor'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'string', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],
            'subscription_id' => ['nullable', 'string', 'exists:subscriptions,id'],
            'category' => ['required', Rule::enum(ComplaintCategory::class)],
            'priority' => ['sometimes', Rule::enum(ComplaintPriority::class)],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $customer = $this->resolveCustomer($request, $data);

        $complaint = $this->workflow->raise($customer, [
            'category' => ComplaintCategory::from($data['category']),
            'priority' => isset($data['priority'])
                ? ComplaintPriority::from($data['priority'])
                : ComplaintPriority::Normal,
            'description' => $data['description'],
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
        ], $request->user());

        return response()->json([
            'data' => new ComplaintResource($complaint->load(['customer', 'vehicle', 'events.actor'])),
        ], 201);
    }

    public function assign(Request $request, Complaint $complaint): JsonResponse
    {
        $this->authorize('assign.complaint');

        $data = $request->validate([
            'assignee_id' => ['required', 'string', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Scoped find: a franchise owner cannot hand their complaint to
        // somebody in another branch, because that person is not visible here.
        $assignee = User::query()->visible()->findOrFail($data['assignee_id']);

        return $this->run(fn () => $this->workflow->assign(
            $complaint, $assignee, $request->user(), $data['note'] ?? null
        ));
    }

    public function addNote(Request $request, Complaint $complaint): JsonResponse
    {
        $this->assertVisible($request, $complaint);

        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->run(fn () => $this->workflow->addNote(
            $complaint, $data['note'], $request->user()
        ));
    }

    public function resolve(Request $request, Complaint $complaint): JsonResponse
    {
        $this->authorize('resolve.complaint');

        // A cleaner may only sign off work that was given to them.
        if ($request->user()->role === UserRole::Cleaner
            && $complaint->assigned_to !== $request->user()->id) {
            abort(403, 'This complaint is not assigned to you.');
        }

        $data = $request->validate(['resolution' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->run(fn () => $this->workflow->resolve(
            $complaint, $data['resolution'], $request->user()
        ));
    }

    public function reopen(Request $request, Complaint $complaint): JsonResponse
    {
        $this->assertVisible($request, $complaint);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->run(fn () => $this->workflow->reopen(
            $complaint, $data['reason'], $request->user()
        ));
    }

    public function close(Request $request, Complaint $complaint): JsonResponse
    {
        $this->authorize('close.complaint');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->run(fn () => $this->workflow->close(
            $complaint, $request->user(), $data['note'] ?? null
        ));
    }

    // ---------------------------------------------------------------- private

    /**
     * Run a workflow move and turn a refused transition into a 422.
     *
     * The workflow throws when a move is not legal. That is a bad request, not
     * a server fault, and the customer facing message says which move was
     * refused rather than "something went wrong".
     */
    private function run(callable $move): JsonResponse
    {
        try {
            $complaint = $move();
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return response()->json([
            'data' => new ComplaintResource(
                $complaint->fresh()->load(['customer', 'vehicle', 'assignee', 'events.actor'])
            ),
        ]);
    }

    /**
     * A customer may only look at their own complaints.
     *
     * The branch scope already stops anyone reading another franchise's. This
     * is the narrower rule inside a branch.
     */
    private function assertVisible(Request $request, Complaint $complaint): void
    {
        if ($request->user()->role !== UserRole::Customer) {
            return;
        }

        $isTheirs = Customer::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($complaint->customer_id)
            ->exists();

        // 404, not 403: confirming it exists tells them about somebody else's
        // complaint.
        abort_unless($isTheirs, 404);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(Request $request, array $data): Customer
    {
        if ($request->user()->role === UserRole::Customer) {
            // A customer raises a complaint as themselves. A customer_id in the
            // body is ignored rather than trusted.
            return Customer::query()
                ->where('user_id', $request->user()->id)
                ->firstOr(fn () => abort(422, 'There is no customer record for this account.'));
        }

        abort_unless(isset($data['customer_id']), 422, 'A customer must be given.');

        return Customer::query()->findOrFail($data['customer_id']);
    }
}
