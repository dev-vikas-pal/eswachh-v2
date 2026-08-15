<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Doing one thing to many plans.
 *
 * The ticked rows are client input. Every test here is about what happens when
 * that input is wrong, doctored, or larger than it should be.
 */
class SubscriptionBulkTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ourBranch;

    private Branch $theirBranch;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        Http::preventStrayRequests();

        $this->ourBranch = Branch::factory()->create();
        $this->theirBranch = Branch::factory()->create();

        $this->seed(\Database\Seeders\MessageTemplateSeeder::class);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    public function test_many_cars_can_be_put_on_one_cleaner(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $ids = collect(range(1, 3))->map(fn () => $this->subscription($this->ourBranch)->id);

        $response = $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/cleaner', [
            'ids' => $ids->all(),
            'cleaner_id' => $cleaner->id,
        ])->assertOk();

        $this->assertSame(3, $response->json('assigned'));

        BranchContext::withoutScope(function () use ($cleaner) {
            $this->assertSame(3, Vehicle::query()->where('assigned_cleaner_id', $cleaner->id)->count());
        });
    }

    public function test_a_doctored_id_list_cannot_reach_another_branch(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $ours = $this->subscription($this->ourBranch);
        $theirs = $this->subscription($this->theirBranch);

        $response = $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/cleaner', [
            'ids' => [$ours->id, $theirs->id],
            'cleaner_id' => $cleaner->id,
        ])->assertOk();

        // The ids are re-read through the scope, so the other branch's plan is
        // simply not there - and it is reported rather than silently dropped.
        $this->assertSame(1, $response->json('assigned'));
        $this->assertSame(1, $response->json('not_visible'));

        BranchContext::withoutScope(function () use ($theirs) {
            $this->assertNull($theirs->vehicle->fresh()->assigned_cleaner_id);
        });
    }

    public function test_a_cleaner_from_another_branch_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $theirCleaner = User::factory()->cleaner($this->theirBranch)->create();
        $ours = $this->subscription($this->ourBranch);

        $response = $this->actingAs($admin)->postJson('/api/v1/subscriptions-bulk/cleaner', [
            'ids' => [$ours->id],
            'cleaner_id' => $theirCleaner->id,
        ])->assertOk();

        // A cleaner in the wrong branch would never see the work.
        $this->assertSame(0, $response->json('assigned'));
        $this->assertNotEmpty($response->json('skipped'));
    }

    public function test_more_than_the_cap_is_refused_outright(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/cleaner', [
            'ids' => array_fill(0, 201, (string) \Illuminate\Support\Str::uuid7()),
            'cleaner_id' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_a_bulk_message_goes_to_every_ticked_row(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $ids = collect(range(1, 3))->map(fn () => $this->subscription($this->ourBranch)->id);

        $response = $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => $ids->all(),
            'template_key' => 'renewal_due',
        ])->assertOk();

        $this->assertSame(3, $response->json('sent'));
        // Never actually delivered from a test run.
        $this->assertFalse($response->json('delivered'));
        $this->assertSame(3, Message::query()->count());
    }

    public function test_the_wording_comes_from_the_template(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $subscription = $this->subscription($this->ourBranch);

        MessageTemplate::query()->where('key', 'renewal_due')->update([
            'body' => 'Hi {name}, {car} is due on {renew_date}. Rs {amount} please.',
        ]);

        $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => [$subscription->id],
            'template_key' => 'renewal_due',
        ])->assertOk();

        $body = Message::query()->value('body');

        // Edited in the office, and that is what went out - placeholders filled.
        $this->assertStringStartsWith('Hi ', $body);
        $this->assertStringNotContainsString('{name}', $body);
        $this->assertStringContainsString($subscription->vehicle->registration, $body);
    }

    public function test_the_same_customer_is_not_messaged_twice_in_a_day(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $subscription = $this->subscription($this->ourBranch);

        $first = $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => [$subscription->id], 'template_key' => 'renewal_due',
        ])->assertOk();

        $second = $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => [$subscription->id], 'template_key' => 'renewal_due',
        ])->assertOk();

        $this->assertSame(1, $first->json('sent'));
        // The same once-a-day rule as the nightly job, through the same code.
        $this->assertSame(0, $second->json('sent'));
        $this->assertSame(1, $second->json('skipped_already_sent'));
        $this->assertSame(1, Message::query()->count());
    }

    public function test_a_customer_with_no_phone_is_counted_not_hidden(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $subscription = $this->subscription($this->ourBranch);

        BranchContext::withoutScope(
            fn () => $subscription->customer->forceFill(['phone' => null])->save()
        );

        $response = $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => [$subscription->id], 'template_key' => 'renewal_due',
        ])->assertOk();

        // Reported, so nobody assumes a customer was told when they were not.
        $this->assertSame(0, $response->json('sent'));
        $this->assertSame(1, $response->json('skipped_no_phone'));
    }

    public function test_a_template_not_meant_for_bulk_is_refused(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $subscription = $this->subscription($this->ourBranch);

        // A receipt for money nobody just paid is worse than no receipt.
        $this->actingAs($owner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => [$subscription->id],
            'template_key' => 'payment_receipt',
        ])->assertStatus(422);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_picker_only_offers_bulk_templates(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $keys = $this->actingAs($owner)->getJson('/api/v1/subscriptions-bulk/templates')
            ->assertOk()->json('data.*.key');

        $this->assertContains('renewal_due', $keys);
        $this->assertNotContains('payment_receipt', $keys);
    }

    public function test_a_cleaner_cannot_bulk_message_anybody(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();
        $subscription = $this->subscription($this->ourBranch);

        $this->actingAs($cleaner)->postJson('/api/v1/subscriptions-bulk/message', [
            'ids' => [$subscription->id], 'template_key' => 'renewal_due',
        ])->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function subscription(Branch $branch): Subscription
    {
        return BranchContext::withoutScope(function () use ($branch) {
            $customer = Customer::factory()->create(['branch_id' => $branch->id]);
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            return Subscription::factory()->forVehicle($vehicle)->create([
                'duration_id' => Duration::factory()->create()->id,
            ]);
        });
    }
}
