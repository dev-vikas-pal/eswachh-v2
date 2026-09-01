<?php

namespace Tests\Feature\Site;

use App\Models\Post;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching a whole feature off has to close every door, not just the menu.
 *
 * The blog and the team page are built, working, and not being run yet. The
 * failure this guards against is the tempting half-measure: hide the link and
 * leave the endpoint answering. A search engine that indexed an article last
 * week goes on sending people to it, and the office can still publish into a
 * section of the site nobody can reach from it.
 */
class FeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_the_blog_is_unreachable_when_it_is_switched_off(): void
    {
        SiteSettings::put(['blog_enabled' => '0']);

        $this->getJson('/api/v1/public/posts')->assertNotFound();
        $this->getJson('/api/v1/public/posts/anything')->assertNotFound();

        // Including the one endpoint on the public site that writes.
        $this->postJson('/api/v1/public/posts/anything/comments', [
            'name' => 'Someone', 'body' => 'Hello',
        ])->assertNotFound();
    }

    public function test_the_office_cannot_write_into_a_switched_off_blog_either(): void
    {
        SiteSettings::put(['blog_enabled' => '0']);

        $admin = User::factory()->superAdmin()->create();

        // Publishing into a section of the site that nobody can reach is an
        // afternoon's work with no result at the end of it.
        $this->actingAs($admin)->getJson('/api/v1/posts')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/v1/posts', ['title' => 'Draft'])->assertNotFound();
    }

    public function test_the_team_page_is_shut_on_its_own_switch(): void
    {
        SiteSettings::put(['team_enabled' => '0', 'blog_enabled' => '1']);

        $this->getJson('/api/v1/public/team')->assertNotFound();

        // Two switches, not one: the blog is still open.
        $this->getJson('/api/v1/public/posts')->assertOk();
    }

    public function test_the_cloth_service_closes_its_endpoints_too(): void
    {
        SiteSettings::put(['cloth_service_enabled' => '0']);

        /*
         * These were the ones left open. The catalogue already withheld the
         * bundles with the service off, so the top-up page loaded, found
         * nothing to sell, and looked broken rather than absent - and the
         * office's two cloth screens went on working entirely.
         */
        $this->postJson('/api/v1/public/cloth/lookup', ['registration' => 'UP42BJ9003'])->assertNotFound();

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->getJson('/api/v1/cloth/outstanding')->assertNotFound();
    }

    public function test_turning_it_back_on_is_only_the_setting(): void
    {
        SiteSettings::put(['blog_enabled' => '1', 'team_enabled' => '1']);

        $this->getJson('/api/v1/public/posts')->assertOk();
        $this->getJson('/api/v1/public/team')->assertOk();
    }

    public function test_the_site_is_told_which_features_are_running(): void
    {
        SiteSettings::put(['blog_enabled' => '0', 'team_enabled' => '1']);

        // The menu and the router draw themselves from this, so it has to
        // agree with what the endpoints above will actually do.
        $features = $this->getJson('/api/v1/public/content')->assertOk()->json('data.features');

        $this->assertFalse($features['blog']);
        $this->assertTrue($features['team']);
    }

    public function test_the_office_is_told_too(): void
    {
        SiteSettings::put(['blog_enabled' => '0']);

        $admin = User::factory()->superAdmin()->create();

        $features = $this->actingAs($admin)->getJson('/api/v1/me')->assertOk()->json('features');

        /*
         * An administrator holds every ability there is, so if the office menu
         * decided by ability alone they would still be offered the blog. What
         * is switched off is switched off for everybody.
         */
        $this->assertFalse($features['blog']);
    }
}
