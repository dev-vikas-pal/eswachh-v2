<?php

namespace Tests\Feature\Api;

use App\Domain\Alerts\AlertRaiser;
use App\Models\Alert;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who sees the bell, and who may silence it.
 *
 * Business-wide alerts - a failed backup - carry no branch. The visibility
 * rule compared branch_id with the viewer's, and a viewer with no branch
 * compared null with null, which the query builder turns into `IS NULL`: every
 * customer could read and resolve them.
 */
class AlertVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Alert $businessWide;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->businessWide = app(AlertRaiser::class)->raise(
            AlertRaiser::BACKUP_FAILED,
            'Last night\'s backup failed',
            severity: 'critical',
        );
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_customer_sees_no_alerts(): void
    {
        $this->actingAs($this->customer())->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_customer_cannot_resolve_a_business_wide_alert(): void
    {
        $this->actingAs($this->customer())
            ->postJson("/api/v1/alerts/{$this->businessWide->id}/resolve")
            ->assertNotFound();

        $this->assertNull($this->businessWide->fresh()->resolved_at);
    }

    public function test_a_customer_cannot_mark_an_alert_read(): void
    {
        $this->actingAs($this->customer())
            ->postJson("/api/v1/alerts/{$this->businessWide->id}/read")
            ->assertNotFound();
    }

    public function test_staff_without_a_branch_do_not_see_business_wide_alerts(): void
    {
        $owner = User::factory()->franchiseOwner()->create();

        $this->actingAs($owner)->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_branch_still_sees_its_own_alerts(): void
    {
        $branch = Branch::factory()->create();
        app(AlertRaiser::class)->raise(AlertRaiser::CLEANER_UNMARKED, 'Nobody marked in', $branch->id);
        $owner = User::factory()->franchiseOwner($branch)->create();

        $this->actingAs($owner)->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Nobody marked in');
    }

    public function test_a_super_admin_sees_and_resolves_business_wide_alerts(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)
            ->postJson("/api/v1/alerts/{$this->businessWide->id}/resolve")
            ->assertOk();

        $this->assertNotNull($this->businessWide->fresh()->resolved_at);
    }

    private function customer(): User
    {
        $user = User::factory()->customer()->create();
        SectorContext::withoutScope(fn () => Customer::factory()->create(['user_id' => $user->id]));

        return $user;
    }
}
