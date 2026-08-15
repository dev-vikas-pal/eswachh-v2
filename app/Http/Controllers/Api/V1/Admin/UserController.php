<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\SortsLists;
use App\Support\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff and customer accounts.
 *
 * The dangerous operation in any user screen is privilege escalation: someone
 * creating an account more powerful than their own, or promoting themselves.
 * Every rule below exists to close one of those doors, and they are checked on
 * the server whatever the form offered.
 */
class UserController extends Controller
{
    use SortsLists;

    private const SORTABLE = [
        'name' => 'name',
        'email' => 'email',
        'role' => 'role',
        'status' => 'status',
        'created' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('view.staff');

        $filters = $request->validate([
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'include_disabled' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /*
         * Staff only. Customers are people with an address, a car and a plan,
         * and they are managed on their own screen against the customers
         * table - listing them here would mix two different things and let
         * somebody create a customer with no address and no vehicle.
         */
        $query = User::query()
            ->with('branch', 'customRole')
            ->where('role', '!=', UserRole::Customer);

        /*
         * The user table is not branch scoped by a global scope - the scope has
         * to read the current user, and scoping the user would be circular - so
         * the filter is applied here, explicitly, on every listing.
         */
        if (! $request->user()->seesAllBranches()) {
            $query->where('branch_id', $request->user()->branch_id);
        }

        if ($role = $filters['role'] ?? null) {
            $query->where('role', $role);
        }

        if ($filters['include_disabled'] ?? false) {
            $query->withTrashed();
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        $this->applySort($query, $request, self::SORTABLE, 'name');

        $users = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => array_map(fn (User $u) => $this->present($u), $users->items()),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                // What this person may create, so the form offers only those
                // and does not have to guess at the rules.
                'assignable_roles' => $this->assignableRoles($request->user()),
            ] + $this->sortMeta($request, self::SORTABLE, 'name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create.staff');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'branch_id' => ['nullable', 'string', 'exists:branches,id'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['sometimes', 'boolean'],
        ]);

        $role = UserRole::from($data['role']);
        $actor = $request->user();

        $this->assertMayAssign($actor, $role);

        // An account with no way to sign in is not an account.
        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'email' => 'An email address or a phone number is needed to sign in.',
            ]);
        }

        $user = BranchContext::withoutScope(fn () => User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $role,
            'branch_id' => $this->resolveBranch($actor, $role, $data['branch_id'] ?? null),
            'password' => Hash::make($data['password']),
            'status' => $data['status'] ?? true,
            'email_verified_at' => now(),
        ]));

        return response()->json(['data' => $this->present($user->load('branch'))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update.staff');
        $this->assertVisible($request->user(), $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at')],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'branch_id' => ['sometimes', 'nullable', 'string', 'exists:branches,id'],
            // Optional: changing a name should not require retyping a password.
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'status' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();

        if (isset($data['role'])) {
            $role = UserRole::from($data['role']);

            // Nobody changes their own role. An administrator who mis-clicks
            // themselves down to cleaner cannot climb back, and a franchise
            // owner promoting themselves is the whole attack.
            if ($user->is($actor)) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot change your own role. Ask another administrator.',
                ]);
            }

            $this->assertMayAssign($actor, $role);
            $user->role = $role;
        }

        if (array_key_exists('branch_id', $data)) {
            // A franchise owner cannot move somebody into - or out of - their
            // branch, which would otherwise be a way to take over an account.
            $user->branch_id = $this->resolveBranch($actor, $user->role, $data['branch_id']);
        }

        foreach (['name', 'email', 'phone', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return response()->json(['data' => $this->present($user->fresh()->load('branch'))]);
    }

    /**
     * Take somebody's access away.
     *
     * A soft delete. Their name is on service logs, complaints and payments
     * they recorded, and a real delete would either break those or quietly
     * detach them from who did the work.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('update.staff');
        $this->assertVisible($request->user(), $user);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'You cannot remove your own account.',
            ]);
        }

        // Removing the last administrator locks everyone out of the system.
        if ($user->role === UserRole::SuperAdmin && $this->otherAdministrators($user) === 0) {
            throw ValidationException::withMessages([
                'user' => 'This is the only administrator. Create another one first.',
            ]);
        }

        $user->forceFill(['status' => false])->save();
        $user->delete();

        return response()->json(['message' => 'Access removed. Their record of past work is kept.']);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.staff');

        $user = User::withTrashed()->findOrFail($id);
        $this->assertVisible($request->user(), $user);

        $user->restore();
        $user->forceFill(['status' => true])->save();

        return response()->json(['data' => $this->present($user->load('branch'))]);
    }

    // ---------------------------------------------------------------- private

    /**
     * Roles this person is allowed to hand out.
     *
     * From the role enum, so the rule lives with the roles rather than being
     * restated here and drifting.
     *
     * @return array<int, array<string, string>>
     */
    private function assignableRoles(User $actor): array
    {
        return array_map(
            fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
            $actor->role?->canCreateStaff() ?? [],
        );
    }

    private function assertMayAssign(User $actor, UserRole $role): void
    {
        $allowed = $actor->role?->canCreateStaff() ?? [];

        if ($role === UserRole::Customer) {
            throw ValidationException::withMessages([
                'role' => 'Customers are added on the Customers screen, where an address and a car can be recorded.',
            ]);
        }

        if (! in_array($role, $allowed, true)) {
            // The single most important check on this screen: without it a
            // franchise owner could create a super admin and own the system.
            throw ValidationException::withMessages([
                'role' => "You cannot create a {$role->label()}.",
            ]);
        }
    }

    /**
     * Which branch a new or edited account belongs to.
     *
     * A franchise owner's people are always their own, whatever the request
     * says. Only a super admin chooses - and a super admin belongs to none.
     */
    private function resolveBranch(User $actor, ?UserRole $role, ?string $requested): ?string
    {
        if ($role === UserRole::SuperAdmin) {
            return null;
        }

        if (! $actor->seesAllBranches()) {
            return $actor->branch_id;
        }

        return $requested;
    }

    private function assertVisible(User $actor, User $target): void
    {
        if ($actor->seesAllBranches()) {
            return;
        }

        // 404 rather than 403: a refusal confirms the account exists, which is
        // itself something about another franchise's staff.
        abort_unless($actor->branch_id && $target->branch_id === $actor->branch_id, 404);
    }

    private function otherAdministrators(User $excluding): int
    {
        return BranchContext::withoutScope(fn () => User::query()
            ->where('role', UserRole::SuperAdmin)
            ->where('status', true)
            ->whereKeyNot($excluding->id)
            ->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => ['value' => $user->role?->value, 'label' => $user->role?->label()],
            /*
             * The role the business defined, if this account has one. Sent as
             * well as the built-in role rather than instead of it: the built-in
             * one still decides branch scoping, so hiding it would make the
             * People screen unable to explain what somebody can see.
             */
            'custom_role' => $user->relationLoaded('customRole') && $user->customRole
                ? [
                    'id' => $user->customRole->id,
                    'name' => $user->customRole->name,
                    'status' => $user->customRole->status,
                ]
                : null,
            'custom_role_id' => $user->custom_role_id,
            'branch' => $user->branch ? ['id' => $user->branch->id, 'name' => $user->branch->name] : null,
            'status' => (bool) $user->status,
            // Access removed, as opposed to switched off. Different things:
            // one is temporary, the other is somebody who has left.
            'removed' => $user->deleted_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
