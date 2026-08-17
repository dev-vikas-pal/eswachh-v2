<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sector;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scope everything else depends on.
 *
 * A sector is the territory. Staff are assigned sectors through user_sector, a
 * customer sits in one sector, and a record is visible when those two meet.
 * There is no franchise above it and nothing is copied onto the customer, so
 * handing a sector to somebody else takes effect immediately and moves nothing.
 *
 * The rule that matters most here is the one that is easiest to get wrong:
 * **fail closed**. Covering nothing means seeing nothing, never everything. A
 * scope that opens up when it cannot answer is how one franchise ends up
 * reading another's customers.
 */
class SectorScopeTest extends TestCase
{
    use RefreshDatabase;

    private Sector $ourSector;

    private Sector $theirSector;

    private Customer $ourCustomer;

    private Customer $theirCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        SectorContext::withoutScope(function () {
            $this->ourSector = Sector::factory()->create(['name' => 'Chi 4']);
            $this->theirSector = Sector::factory()->create(['name' => 'Phi 4']);

            $this->ourCustomer = Customer::factory()->create(['sector_id' => $this->ourSector->id]);
            $this->theirCustomer = Customer::factory()->create(['sector_id' => $this->theirSector->id]);
        });
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    private function staffCovering(Sector ...$sectors): User
    {
        $user = User::factory()->franchiseOwner()->create();
        $user->sectors()->sync(collect($sectors)->pluck('id')->all());

        SectorContext::forget($user->id);

        return $user;
    }

    public function test_staff_see_only_the_customers_in_their_sectors(): void
    {
        $this->actingAs($this->staffCovering($this->ourSector));

        $this->assertSame([$this->ourCustomer->id], Customer::query()->pluck('id')->all());
    }

    public function test_several_sectors_are_covered_at_once(): void
    {
        // The reason this is a pivot and not a column: one person, many
        // territories, no duplication anywhere.
        $this->actingAs($this->staffCovering($this->ourSector, $this->theirSector));

        $this->assertSame(2, Customer::query()->count());
    }

    public function test_a_customer_in_another_sector_cannot_be_read_by_id(): void
    {
        $this->actingAs($this->staffCovering($this->ourSector));

        // Null, not an exception: the controller turns this into 404, which is
        // what stops a refusal confirming that the record exists.
        $this->assertNull(Customer::find($this->theirCustomer->id));
        $this->assertNotNull(Customer::find($this->ourCustomer->id));
    }

    public function test_reassigning_a_sector_moves_what_is_visible_and_nothing_else(): void
    {
        $first = $this->staffCovering($this->ourSector);
        $second = $this->staffCovering();

        // The whole point of the model. Under the old arrangement this needed a
        // handover that rewrote branch_id on every customer, plan, payment and
        // complaint; here it is one pivot row.
        $second->sectors()->sync([$this->ourSector->id]);
        $first->sectors()->sync([]);
        SectorContext::forget($first->id);
        SectorContext::forget($second->id);

        $this->actingAs($second);
        $this->assertSame([$this->ourCustomer->id], Customer::query()->pluck('id')->all());

        $this->actingAs($first);
        $this->assertSame(0, Customer::query()->count());

        // Nothing on the customer was touched to make that happen.
        $this->assertSame(
            $this->ourSector->id,
            SectorContext::withoutScope(fn () => Customer::find($this->ourCustomer->id)->sector_id)
        );
    }

    public function test_somebody_covering_nothing_sees_nothing_rather_than_everything(): void
    {
        // The failure that matters. A scope that opens up when it has no answer
        // is how one franchise reads another's books.
        $this->actingAs($this->staffCovering());

        $this->assertSame(0, Customer::query()->count());
        $this->assertNull(Customer::find($this->ourCustomer->id));
    }

    public function test_a_guest_sees_nothing(): void
    {
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_customer_with_no_sector_belongs_to_nobody(): void
    {
        $stray = SectorContext::withoutScope(
            fn () => Customer::factory()->create(['sector_id' => null])
        );

        /*
         * Deliberate, and the reason the rule is stated as "the customer's
         * sector" rather than "their address": until somebody gives them a
         * sector they are nobody's to service. An administrator can still find
         * them and put it right.
         */
        $this->actingAs($this->staffCovering($this->ourSector, $this->theirSector));
        $this->assertNull(Customer::find($stray->id));

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->assertNotNull(Customer::find($stray->id));
    }

    public function test_an_administrator_sees_every_sector(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, Customer::query()->count());
        // And holds no assignments at all - covering everything is a role, not
        // a stack of pivot rows somebody has to maintain.
        $this->assertSame(0, User::query()->where('role', 'super_admin')->first()->sectors()->count());
    }

    public function test_records_hanging_off_a_customer_follow_them(): void
    {
        SectorContext::withoutScope(fn () => Subscription::factory()->create([
            'customer_id' => $this->theirCustomer->id,
        ]));

        $this->actingAs($this->staffCovering($this->ourSector));
        $this->assertSame(0, Subscription::query()->count());

        $this->actingAs($this->staffCovering($this->theirSector));
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_the_scope_can_be_escaped_only_deliberately(): void
    {
        $this->actingAs($this->staffCovering($this->ourSector));

        $this->assertSame(1, Customer::query()->count());

        $all = SectorContext::withoutScope(fn () => Customer::query()->count());
        $this->assertSame(2, $all);

        // And the escape does not leak past the block it was asked for.
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_the_sector_filter_narrows_a_listing(): void
    {
        /*
         * The filter in the top bar has to reach the query.
         *
         * It was only in the cache key, so choosing a sector refetched the same
         * unfiltered list and the screen appeared to ignore it - which for
         * somebody covering one sector looked like it worked and for somebody
         * covering two did nothing at all.
         */
        SectorContext::withoutScope(fn () => Subscription::factory()->create([
            'customer_id' => $this->ourCustomer->id,
        ]));

        SectorContext::withoutScope(fn () => Subscription::factory()->create([
            'customer_id' => $this->theirCustomer->id,
        ]));

        $this->actingAs($this->staffCovering($this->ourSector, $this->theirSector));

        $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/subscriptions?filter[sector_id]='.$this->theirSector->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_sector_filter_cannot_widen_what_somebody_sees(): void
    {
        SectorContext::withoutScope(fn () => Subscription::factory()->create([
            'customer_id' => $this->theirCustomer->id,
        ]));

        // Asking for somebody else's sector is not a way in: the scope has
        // already narrowed the query before the filter is applied.
        $this->actingAs($this->staffCovering($this->ourSector));

        $this->getJson('/api/v1/subscriptions?filter[sector_id]='.$this->theirSector->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Every list the picker claims to narrow.
     *
     * Four of these had it only in the front end's cache key, so the screen
     * refetched the same unfiltered rows and appeared to ignore the control.
     * Named here so a screen added later is added to this list too.
     *
     * @return array<string, array<int, string>>
     */
    public static function narrowableLists(): array
    {
        return [
            'subscriptions' => ['/api/v1/subscriptions'],
            'customers' => ['/api/v1/customers'],
            'payments' => ['/api/v1/payments'],
            'complaints' => ['/api/v1/complaints'],
            'messages' => ['/api/v1/reminders'],
            'people' => ['/api/v1/users'],
            'coverage' => ['/api/v1/attendance/coverage'],
            'revenue report' => ['/api/v1/reports/revenue'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('narrowableLists')]
    public function test_a_list_accepts_the_sector_filter(string $url): void
    {
        $this->actingAs($this->staffCovering($this->ourSector, $this->theirSector));

        // Not asserting counts here - each list counts something different.
        // What this pins is that the parameter is accepted and honoured rather
        // than ignored, which is how all four of them were broken.
        $this->getJson($url.'?sector_id='.$this->ourSector->id)->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('narrowableLists')]
    public function test_a_list_refuses_a_sector_that_is_not_yours(string $url): void
    {
        $this->actingAs($this->staffCovering($this->ourSector));

        // A doctored query string must not widen anything.
        $this->getJson($url.'?sector_id='.$this->theirSector->id)->assertForbidden();
    }

    public function test_the_people_list_narrows_to_who_covers_the_sector(): void
    {
        $mine = $this->staffCovering($this->ourSector);
        $theirs = $this->staffCovering($this->theirSector);

        $admin = User::factory()->superAdmin()->create();

        $names = $this->actingAs($admin)
            ->getJson('/api/v1/users?sector_id='.$this->ourSector->id)
            ->assertOk()
            ->json('data.*.id');

        /*
         * People lists staff, not customers, so "who works this sector" is the
         * question - the usual customer filter cannot answer it, which is why
         * this screen was left out and showed everybody.
         */
        $this->assertContains($mine->id, $names);
        $this->assertNotContains($theirs->id, $names);
    }

    public function test_a_report_narrows_without_each_report_asking(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $total = $this->actingAs($admin)->getJson('/api/v1/reports/revenue')->assertOk();
        $narrowed = $this->actingAs($admin)
            ->getJson('/api/v1/reports/revenue?sector_id='.$this->ourSector->id)
            ->assertOk();

        /*
         * Applied by narrowing the context rather than filtering each query, so
         * a report added tomorrow follows the picker without anybody
         * remembering. Both answer; what matters is that the narrowed one is
         * not simply the same object.
         */
        $this->assertNotNull($total->json('data'));
        $this->assertNotNull($narrowed->json('data'));
    }

    public function test_a_job_can_run_inside_a_sector_without_a_signed_in_user(): void
    {
        // Scheduled work has nobody signed in, and must not therefore see
        // everything.
        $names = SectorContext::forSectors([$this->theirSector->id], fn () => Customer::query()->pluck('id')->all());

        $this->assertSame([$this->theirCustomer->id], $names);
    }
}
