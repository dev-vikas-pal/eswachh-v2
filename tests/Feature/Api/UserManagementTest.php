<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The user screen is where privilege escalation lives.
 *
 * Every test here is one door: creating an account more powerful than your own,
 * promoting yourself, or reaching into another franchise's staff.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ourBranch;

    private Branch $theirBranch;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->ourBranch = Branch::factory()->create();
        $this->theirBranch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    public function test_a_franchise_owner_cannot_create_an_administrator(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@eswachh.test',
            'role' => UserRole::SuperAdmin->value, 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        // The single most important check on this screen.
        $this->assertSame(0, User::query()->where('role', UserRole::SuperAdmin)->count());
    }

    public function test_a_franchise_owner_cannot_create_another_franchise_owner(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'Rival', 'email' => 'rival@eswachh.test',
            'role' => UserRole::FranchiseOwner->value, 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_a_franchise_owner_can_create_a_cleaner_in_their_own_branch(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'Ramesh', 'phone' => '9876543210',
            'role' => UserRole::Cleaner->value, 'password' => 'password123',
        ])->assertCreated();

        $this->assertSame($this->ourBranch->id, $response->json('data.branch.id'));
    }

    public function test_a_franchise_owner_cannot_place_somebody_in_another_branch(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'Ramesh', 'phone' => '9876500000',
            'role' => UserRole::Cleaner->value, 'password' => 'password123',
            // Asking for the other branch. Ignored, not obeyed.
            'branch_id' => $this->theirBranch->id,
        ])->assertCreated();

        $this->assertSame($this->ourBranch->id, $response->json('data.branch.id'));
    }

    public function test_nobody_can_change_their_own_role(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->patchJson("/api/v1/users/{$owner->id}", [
            'role' => UserRole::SuperAdmin->value,
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::FranchiseOwner, $owner->fresh()->role);
    }

    public function test_a_franchise_owner_cannot_see_another_branch_staff(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        User::factory()->cleaner($this->ourBranch)->count(2)->create();
        User::factory()->cleaner($this->theirBranch)->count(5)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/users')->assertOk();

        // Two cleaners plus themselves. Not the other franchise's five.
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_editing_another_branch_account_is_not_found_rather_than_forbidden(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $theirs = User::factory()->cleaner($this->theirBranch)->create();

        // 404: a refusal would confirm the account exists.
        $this->actingAs($owner)->patchJson("/api/v1/users/{$theirs->id}", ['name' => 'Renamed'])
            ->assertNotFound();
    }

    public function test_the_form_only_offers_staff_roles(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $roles = $this->actingAs($owner)->getJson('/api/v1/users')->json('meta.assignable_roles');

        // Customer is absent: they are added on the Customers screen, where an
        // address and a car can be recorded.
        $this->assertSame(['cleaner'], array_column($roles, 'value'));
    }

    public function test_an_administrator_can_create_any_member_of_staff(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach ([UserRole::SuperAdmin, UserRole::FranchiseOwner, UserRole::Cleaner] as $i => $role) {
            $this->actingAs($admin)->postJson('/api/v1/users', [
                'name' => 'Person '.$i,
                'email' => "person{$i}@eswachh.test",
                'role' => $role->value,
                'branch_id' => $this->ourBranch->id,
                'password' => 'password123',
            ])->assertCreated();
        }
    }

    public function test_a_customer_cannot_be_created_from_the_staff_screen(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Walk-in customer',
            'email' => 'walkin@eswachh.test',
            'role' => UserRole::Customer->value,
            'branch_id' => $this->ourBranch->id,
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        // Not merely refused - the message says where to go instead, because
        // an account with no address and no car cannot be serviced.
        $this->assertStringContainsString('Customers screen', $response->json('errors.role.0'));
    }

    public function test_customers_do_not_appear_in_the_staff_list(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->cleaner($this->ourBranch)->count(2)->create();
        User::factory()->customer($this->ourBranch)->count(7)->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();

        // Two cleaners plus the administrator. The seven customers belong on
        // their own screen, against their own table.
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_an_administrator_belongs_to_no_branch_whatever_is_requested(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Second admin', 'email' => 'admin2@eswachh.test',
            'role' => UserRole::SuperAdmin->value,
            'branch_id' => $this->ourBranch->id,
            'password' => 'password123',
        ])->assertCreated();

        // A super admin sees every branch, so belonging to one would be
        // meaningless and would quietly narrow what they can see.
        $this->assertNull($response->json('data.branch'));
    }

    public function test_an_account_with_no_way_to_sign_in_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Nameless', 'role' => UserRole::Cleaner->value,
            'branch_id' => $this->ourBranch->id, 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $other = User::factory()->superAdmin()->create();

        // Removing one of two is fine.
        $this->actingAs($admin)->deleteJson("/api/v1/users/{$other->id}")->assertOk();

        $another = User::factory()->superAdmin()->create();

        // But the last one standing would lock everybody out of the system.
        $this->actingAs($another)->deleteJson("/api/v1/users/{$admin->id}")->assertOk();
        $this->actingAs($another)->deleteJson("/api/v1/users/{$another->id}")
            ->assertStatus(422);
    }

    public function test_removing_access_keeps_the_record_of_past_work(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$cleaner->id}")->assertOk();

        // Soft deleted: their name is on service logs and complaints, and a
        // real delete would detach those from whoever did the work.
        $this->assertSoftDeleted('users', ['id' => $cleaner->id]);
        $this->assertNotNull(User::withTrashed()->find($cleaner->id));
    }

    public function test_a_password_is_only_changed_when_one_is_given(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $cleaner = User::factory()->cleaner($this->ourBranch)->create([
            'password' => Hash::make('original-password'),
        ]);

        $this->actingAs($admin)->patchJson("/api/v1/users/{$cleaner->id}", ['name' => 'Renamed'])
            ->assertOk();

        // Renaming somebody must not quietly lock them out.
        $this->assertTrue(Hash::check('original-password', $cleaner->fresh()->password));
    }

    public function test_a_cleaner_cannot_reach_the_user_list_at_all(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($cleaner)->getJson('/api/v1/users')->assertForbidden();
    }
}
