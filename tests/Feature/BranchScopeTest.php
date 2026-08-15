<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Sector;
use App\Models\User;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branch scope is the security boundary of the whole application, so it is
 * tested from the outside: not "is the trait applied", but "can one franchise
 * reach another's data by any route we can think of".
 */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ourBranch;

    private Branch $theirBranch;

    private Sector $ourSector;

    private Sector $theirSector;

    protected function setUp(): void
    {
        parent::setUp();

        BranchContext::reset();

        $this->ourBranch = Branch::factory()->create(['name' => 'Franchise A']);
        $this->theirBranch = Branch::factory()->create(['name' => 'Franchise B']);

        // Created outside any scope, the way a seeder or admin would.
        BranchContext::withoutScope(function () {
            $this->ourSector = Sector::factory()->forBranch($this->ourBranch)->create(['name' => 'Chi 4']);
            $this->theirSector = Sector::factory()->forBranch($this->theirBranch)->create(['name' => 'Phi 4']);
        });
    }

    protected function tearDown(): void
    {
        BranchContext::reset();

        parent::tearDown();
    }

    public function test_a_franchise_owner_sees_only_their_own_branch(): void
    {
        $this->actingAs(User::factory()->franchiseOwner($this->ourBranch)->create());

        $names = Sector::query()->pluck('name')->all();

        $this->assertSame(['Chi 4'], $names);
    }

    public function test_a_franchise_owner_cannot_read_another_branch_by_id(): void
    {
        $this->actingAs(User::factory()->franchiseOwner($this->ourBranch)->create());

        // Knowing the id is not enough; the scope still applies.
        $this->assertNull(Sector::find($this->theirSector->id));
        $this->assertNotNull(Sector::find($this->ourSector->id));
    }

    public function test_a_franchise_owner_cannot_count_another_branch(): void
    {
        $this->actingAs(User::factory()->franchiseOwner($this->ourBranch)->create());

        // Aggregates leak just as easily as lists if the scope is per query.
        $this->assertSame(1, Sector::query()->count());
        $this->assertSame(0, Sector::query()->where('name', 'Phi 4')->count());
    }

    public function test_a_user_with_no_branch_sees_nothing_rather_than_everything(): void
    {
        // The failure mode that matters. A misconfigured account must not
        // become an administrator by accident.
        $this->actingAs(User::factory()->franchiseOwner()->withoutBranch()->create());

        $this->assertSame(0, Sector::query()->count());
        $this->assertNull(Sector::find($this->ourSector->id));
    }

    public function test_a_guest_sees_nothing(): void
    {
        $this->assertSame(0, Sector::query()->count());
    }

    public function test_a_super_admin_sees_every_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, Sector::query()->count());
    }

    public function test_a_cleaner_is_held_to_their_branch_too(): void
    {
        $this->actingAs(User::factory()->cleaner($this->theirBranch)->create());

        $this->assertSame(['Phi 4'], Sector::query()->pluck('name')->all());
    }

    public function test_new_records_land_in_the_current_branch_without_being_told(): void
    {
        $this->actingAs(User::factory()->franchiseOwner($this->ourBranch)->create());

        $sector = Sector::factory()->create(['name' => 'Chi 5']);

        $this->assertSame($this->ourBranch->id, $sector->branch_id);
    }

    public function test_the_scope_can_be_escaped_only_deliberately(): void
    {
        $this->actingAs(User::factory()->franchiseOwner($this->ourBranch)->create());

        $this->assertSame(1, Sector::query()->count());

        $all = BranchContext::withoutScope(fn () => Sector::query()->count());
        $this->assertSame(2, $all);

        // And the escape does not leak past the block.
        $this->assertSame(1, Sector::query()->count());
    }

    public function test_a_job_can_run_inside_one_branch_without_a_logged_in_user(): void
    {
        // Scheduled work has no authenticated user, but must still be scoped.
        $names = BranchContext::forBranch(
            $this->theirBranch->id,
            fn () => Sector::query()->pluck('name')->all()
        );

        $this->assertSame(['Phi 4'], $names);
    }
}
