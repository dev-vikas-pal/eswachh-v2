<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CustomRole;
use App\Models\User;
use App\Support\Access\Abilities;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Roles the business defines for itself.
 *
 * Super admin only, and that is asked directly rather than through an ability.
 * If "manage roles" were an ability it could be put on a role, and that role
 * could then grant itself everything else - the classic way a permissions
 * system defeats itself.
 *
 * What a custom role can change is what somebody may *do*. What it can never
 * change is which branch they see: that comes from the built-in role, is not on
 * the ability list, and has no checkbox anywhere.
 */
class RoleController extends Controller
{
    /**
     * The ability list, for the screen that builds a role.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $this->onlySuperAdmin($request);

        $modules = [];

        foreach (Abilities::catalogue() as $module => $abilities) {
            $modules[] = [
                'module' => $module,
                'abilities' => array_map(
                    fn ($key, $label) => ['key' => $key, 'label' => $label],
                    array_keys($abilities),
                    array_values($abilities),
                ),
            ];
        }

        return response()->json([
            'data' => $modules,
            'base_roles' => array_map(fn (UserRole $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'sees_all_sectors' => $r->seesAllSectors(),
                // What they would get with no customisation, so somebody can
                // start from a sensible set rather than an empty screen.
                'abilities' => $r === UserRole::SuperAdmin ? Abilities::all() : $r->abilities(),
            ], $this->baseRoles()),
            'note' => 'A role only decides what somebody may do. Which branch they see comes from '
                .'the built-in role and cannot be changed here.',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->onlySuperAdmin($request);

        $roles = CustomRole::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (CustomRole $role) => $this->present($role))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->onlySuperAdmin($request);

        $data = $this->validated($request);

        $role = CustomRole::create($data);

        return response()->json(['data' => $this->present($role->fresh()->loadCount('users'))], 201);
    }

    public function update(Request $request, CustomRole $role): JsonResponse
    {
        $this->onlySuperAdmin($request);

        $role->update($this->validated($request, $role));

        return response()->json(['data' => $this->present($role->fresh()->loadCount('users'))]);
    }

    public function destroy(Request $request, CustomRole $role): JsonResponse
    {
        $this->onlySuperAdmin($request);

        /*
         * The accounts holding it are not deleted or locked - they fall back to
         * their built-in role, which is what they had before anybody customised
         * anything. Said plainly in the reply, because "deleted the role" and
         * "gave five people their old permissions back" are different enough
         * that somebody should be told.
         */
        $affected = $role->users()->count();

        $role->users()->update(['custom_role_id' => null]);
        $role->delete();

        return response()->json([
            'message' => $affected === 0
                ? 'Role deleted.'
                : "Role deleted. {$affected} account(s) went back to their built-in permissions.",
        ]);
    }

    /**
     * Give somebody a custom role, or take it away.
     */
    public function assign(Request $request, User $user): JsonResponse
    {
        $this->onlySuperAdmin($request);

        $data = $request->validate([
            'custom_role_id' => ['present', 'nullable', 'string', 'exists:custom_roles,id'],
        ]);

        if ($user->role === UserRole::SuperAdmin) {
            // A super admin is allowed everything by a Gate hook that runs
            // before abilities are consulted, so a custom role on one would sit
            // there looking like it did something and do nothing at all.
            throw ValidationException::withMessages([
                'custom_role_id' => 'An administrator already has every permission; a role would have no effect.',
            ]);
        }

        if ($data['custom_role_id']) {
            $role = CustomRole::query()->findOrFail($data['custom_role_id']);

            if ($role->base_role !== $user->role) {
                /*
                 * A role built for a franchise owner assumes a branch and a
                 * staff screen. Putting it on a cleaner would grant abilities
                 * the interface never expected that person to have.
                 */
                throw ValidationException::withMessages([
                    'custom_role_id' => 'That role was built for a '.$role->base_role->label()
                        .', and this account is a '.$user->role->label().'.',
                ]);
            }
        }

        $user->forceFill(['custom_role_id' => $data['custom_role_id']])->save();

        return response()->json([
            'message' => $data['custom_role_id'] ? 'Role applied.' : 'Role removed.',
            'data' => [
                'id' => $user->id,
                'custom_role_id' => $user->custom_role_id,
                'abilities' => $user->fresh()->abilities(),
            ],
        ]);
    }

    // --------------------------------------------------------------- private

    private function onlySuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === UserRole::SuperAdmin, 403,
            'Only an administrator can manage roles.');
    }

    /**
     * Built-in roles a custom role may be based on.
     *
     * Not the super admin: it is allowed everything by a Gate hook that runs
     * before any ability is read, so a custom role based on it could only ever
     * be ignored. Offering it would be offering something that does nothing.
     *
     * @return array<int, UserRole>
     */
    private function baseRoles(): array
    {
        return array_values(array_filter(
            UserRole::cases(),
            fn (UserRole $r) => $r !== UserRole::SuperAdmin,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CustomRole $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [
                $required, 'string', 'max:60',
                Rule::unique('custom_roles', 'name')->ignore($existing?->id)->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_role' => [
                $required,
                Rule::in(array_map(fn (UserRole $r) => $r->value, $this->baseRoles())),
            ],
            'abilities' => [$required, 'array'],
            // Each one checked against the catalogue, so a client cannot invent
            // an ability name and have it stored for somebody to trip over.
            'abilities.*' => ['string', Rule::in(Abilities::grantable())],
            'status' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['abilities'])) {
            $data['abilities'] = array_values(array_unique($data['abilities']));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomRole $role): array
    {
        $byModule = [];

        foreach ($role->grants() as $ability) {
            $byModule[Abilities::moduleOf($ability) ?? 'Other'][] = $ability;
        }

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'base_role' => $role->base_role->value,
            'base_role_label' => $role->base_role->label(),
            'abilities' => $role->grants(),
            // Grouped as well as listed, so a long checkbox list reads as
            // "Complaints, everything; Payments, view only" at a glance.
            'by_module' => $byModule,
            'status' => $role->status,
            'users_count' => $role->users_count ?? 0,
        ];
    }
}
