<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CustomRole;
use App\Models\User;
use App\Support\Access\Abilities;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Roles the business defines for itself.
 *
 * The interesting tests are the ones about what a role cannot do. A permissions
 * screen that can accidentally grant sight of another franchise, or that lets a
 * role widen itself, is worse than having no permissions screen at all.
 */
class CustomRoleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->superAdmin()->create();
        $this->owner = User::factory()->franchiseOwner($this->branch)->create();
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------- managing

    public function test_an_administrator_can_build_a_supervisor_role(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/roles', [
                'name' => 'Supervisor',
                'description' => 'Runs the day, does not touch money',
                'base_role' => 'franchise_owner',
                'abilities' => ['view.dashboard', 'view.subscription', 'view.round', 'record.service'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Supervisor')
            ->assertJsonCount(4, 'data.abilities');
    }

    public function test_nobody_but_an_administrator_can_touch_roles(): void
    {
        // Asked as "are you a super admin", not as an ability, so that no role
        // can ever be given the power to rewrite roles.
        $this->actingAs($this->owner)->getJson('/api/v1/roles')->assertForbidden();

        $this->actingAs($this->owner)
            ->postJson('/api/v1/roles', [
                'name' => 'Mine', 'base_role' => 'franchise_owner', 'abilities' => ['manage.master'],
            ])
            ->assertForbidden();
    }

    public function test_an_invented_ability_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/roles', [
                'name' => 'Odd',
                'base_role' => 'franchise_owner',
                'abilities' => ['view.dashboard', 'delete.everything'],
            ])
            ->assertStatus(422)
            // Index 1 is the invented one; index 0 is fine.
            ->assertJsonValidationErrors('abilities.1');
    }

    public function test_a_role_cannot_be_based_on_the_administrator(): void
    {
        // A super admin is allowed everything by a Gate hook that runs before
        // abilities are read, so such a role could only ever be ignored.
        $this->actingAs($this->admin)
            ->postJson('/api/v1/roles', [
                'name' => 'Shadow admin', 'base_role' => 'super_admin', 'abilities' => ['view.dashboard'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_role');
    }

    public function test_two_roles_cannot_share_a_name(): void
    {
        $this->role(['name' => 'Supervisor']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/roles', [
                'name' => 'Supervisor', 'base_role' => 'franchise_owner', 'abilities' => ['view.dashboard'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ------------------------------------------------------------- applying

    public function test_a_role_narrows_what_somebody_may_do(): void
    {
        $role = $this->role(['abilities' => ['view.dashboard', 'view.subscription']]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/users/'.$this->owner->id.'/role', ['custom_role_id' => $role->id])
            ->assertOk();

        $owner = $this->owner->fresh();

        // A franchise owner normally holds all of these.
        $this->assertTrue($owner->hasAbility('view.subscription'));
        $this->assertFalse($owner->hasAbility('create.payment'));
        $this->assertFalse($owner->hasAbility('view.customer'));
    }

    public function test_the_narrowed_role_actually_closes_the_screens(): void
    {
        $role = $this->role(['abilities' => ['view.dashboard']]);
        $this->owner->forceFill(['custom_role_id' => $role->id])->save();

        // The point of the whole feature: the ability list is not decoration,
        // it is what the endpoints check.
        $this->actingAs($this->owner->fresh())->getJson('/api/v1/customers')->assertForbidden();
        $this->actingAs($this->owner->fresh())->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_a_role_can_never_widen_which_branch_somebody_sees(): void
    {
        // Every ability there is, and still only their own branch: seeing
        // across branches is not an ability at all.
        $role = $this->role(['abilities' => Abilities::all()]);
        $this->owner->forceFill(['custom_role_id' => $role->id])->save();

        $this->assertFalse($this->owner->fresh()->seesAllBranches());
    }

    public function test_a_role_built_for_one_kind_of_account_cannot_be_put_on_another(): void
    {
        $role = $this->role(['base_role' => 'franchise_owner']);
        $cleaner = User::factory()->cleaner($this->branch)->create();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/users/'.$cleaner->id.'/role', ['custom_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_role_id');
    }

    public function test_a_role_on_an_administrator_is_refused_rather_than_ignored(): void
    {
        $role = $this->role();

        // It would silently do nothing, which is worse than saying no.
        $this->actingAs($this->admin)
            ->postJson('/api/v1/users/'.$this->admin->id.'/role', ['custom_role_id' => $role->id])
            ->assertStatus(422);
    }

    public function test_switching_a_role_off_returns_people_to_their_built_in_permissions(): void
    {
        $role = $this->role(['abilities' => ['view.dashboard']]);
        $this->owner->forceFill(['custom_role_id' => $role->id])->save();

        $this->assertFalse($this->owner->fresh()->hasAbility('view.customer'));

        $role->forceFill(['status' => false])->save();

        // Not locked out. A role switched off overnight should leave somebody
        // with what they had before anybody customised anything.
        $this->assertTrue($this->owner->fresh()->hasAbility('view.customer'));
    }

    public function test_deleting_a_role_frees_the_accounts_holding_it(): void
    {
        $role = $this->role(['abilities' => ['view.dashboard']]);
        $this->owner->forceFill(['custom_role_id' => $role->id])->save();

        $this->actingAs($this->admin)
            ->deleteJson('/api/v1/roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('message', 'Role deleted. 1 account(s) went back to their built-in permissions.');

        $this->assertNull($this->owner->fresh()->custom_role_id);
        $this->assertTrue($this->owner->fresh()->hasAbility('view.customer'));
    }

    // ------------------------------------------------------------- catalogue

    public function test_every_ability_the_code_checks_is_on_the_menu(): void
    {
        /*
         * The guard against drift. An ability checked somewhere but missing
         * from the catalogue can never be granted, so the screen behind it is
         * silently unreachable for every custom role - and nobody would find
         * out until a supervisor said "this button does nothing".
         */
        $found = [];

        foreach (array_merge(
            File::allFiles(app_path()),
            File::allFiles(base_path('routes')),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/(?:authorize\(|hasAbility\(|'can:)['\"]?([a-z]+\.[a-z.]+)/",
                $file->getContents(),
                $matches,
            );

            foreach ($matches[1] as $ability) {
                $found[$ability] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($found), Abilities::all()));

        $this->assertSame([], $missing,
            'These abilities are checked in the code but are not in Abilities::catalogue(): '
            .implode(', ', $missing));
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function role(array $attributes = []): CustomRole
    {
        return CustomRole::create(array_merge([
            'name' => 'Supervisor',
            'description' => null,
            'base_role' => UserRole::FranchiseOwner,
            'abilities' => ['view.dashboard', 'view.subscription'],
            'status' => true,
        ], $attributes));
    }
}
