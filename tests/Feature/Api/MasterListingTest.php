<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Sector;
use App\Models\User;
use App\Support\Masters\MasterRegistry;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every master list opens.
 *
 * The Masters screen had no test at all, and an undefined variable inside the
 * listing closure took out all twenty of them at once - the price list, the
 * geography, the banners, the whole screen - with nothing but "Something went
 * wrong" on the front and a stack trace in the log.
 *
 * The point of walking the registry rather than naming a few is that a master
 * added later is covered the day it is added, without anybody remembering to
 * come back here.
 */
class MasterListingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        /*
         * Every feature on, so the walk below covers every master.
         *
         * Three of them - the blog's categories and tags, and the team - belong
         * to features that ship switched off, and this test exists to prove the
         * listing code works for all of them rather than for whichever ones the
         * business happens to be running today. That the gate itself works is
         * a separate test, below.
         */
        SiteSettings::put([
            'blog_enabled' => '1',
            'team_enabled' => '1',
            'cloth_service_enabled' => '1',
        ]);

        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->superAdmin()->create();
    }

    public function test_a_master_behind_a_switched_off_feature_is_neither_listed_nor_readable(): void
    {
        SiteSettings::put(['blog_enabled' => '0']);

        $offered = array_column(
            $this->actingAs($this->admin)->getJson('/api/v1/masters')->assertOk()->json('data'),
            'key',
        );

        $this->assertNotContains('post-categories', $offered);
        $this->assertNotContains('post-tags', $offered);

        /*
         * And the endpoint behind it is shut too.
         *
         * Hiding the menu entry alone would leave the master editable by
         * anybody who kept the address - which is a door left open, not a
         * feature switched off.
         */
        $this->actingAs($this->admin)
            ->getJson('/api/v1/masters/post-categories')
            ->assertNotFound();
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function masters(): array
    {
        return array_map(
            fn (string $key) => [$key],
            array_combine(array_keys(MasterRegistry::all()), array_keys(MasterRegistry::all())),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('masters')]
    public function test_a_master_lists_without_error(string $master): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/v1/masters/{$master}")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['key', 'label', 'singular']])
            ->assertJsonPath('meta.key', $master);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('masters')]
    public function test_a_master_lists_with_every_filter_applied(string $master): void
    {
        // Search and the withdrawn switch take different paths through the
        // query, and the failure that prompted this test was in code all three
        // paths run through.
        $this->actingAs($this->admin)
            ->getJson("/api/v1/masters/{$master}?search=zzz&include_withdrawn=1")
            ->assertOk();
    }

    public function test_the_catalogue_lists_every_master(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/masters')->assertOk();

        $offered = array_column($response->json('data'), 'key');

        foreach (array_keys(MasterRegistry::all()) as $key) {
            $this->assertContains($key, $offered, "The menu does not offer {$key}.");
        }
    }

    public function test_the_price_list_is_not_open_to_a_franchise(): void
    {
        /*
         * Masters is the price list, and every franchise sells from the same
         * one. A franchise owner editing it would be changing what every other
         * franchise charges, so the whole screen is administrator-only - which
         * is why the staff picker's own filtering is a second line rather than
         * the first.
         */
        $owner = User::factory()->franchiseOwner($this->branch)->create();

        $this->assertSame(UserRole::FranchiseOwner, $owner->role);

        $this->actingAs($owner)->getJson('/api/v1/masters/sectors')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/masters/packages')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/masters/packages', ['name' => 'Mine'])->assertForbidden();
    }

    public function test_a_master_that_does_not_exist_is_refused(): void
    {
        // The route could otherwise name any table, which would be one endpoint
        // for editing anything in the database.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/masters/users')
            ->assertNotFound();
    }
}
