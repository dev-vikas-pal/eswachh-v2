<?php

namespace Tests\Feature\Api;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ourBranch;

    private Branch $theirBranch;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->ourBranch = Branch::factory()->create(['code' => 'AAA']);
        $this->theirBranch = Branch::factory()->create(['code' => 'BBB']);
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    public function test_a_customer_raises_a_complaint_as_themselves(): void
    {
        [$user, $customer] = $this->customerAccount($this->ourBranch);
        [, $someoneElse] = $this->customerAccount($this->ourBranch);

        $response = $this->actingAs($user)->postJson('/api/v1/complaints', [
            // A customer_id in the body is ignored, not trusted. Otherwise
            // anyone could raise a complaint in someone else's name.
            'customer_id' => $someoneElse->id,
            'category' => ComplaintCategory::NotCleaned->value,
            'description' => 'My car was not cleaned this morning.',
        ])->assertCreated();

        $this->assertSame($customer->id, $response->json('data.customer.id'));
        $this->assertNotSame($someoneElse->id, $response->json('data.customer.id'));
    }

    public function test_a_customer_only_sees_their_own_complaints(): void
    {
        [$user, $customer] = $this->customerAccount($this->ourBranch);
        [, $neighbour] = $this->customerAccount($this->ourBranch);

        SectorContext::withoutScope(function () use ($customer, $neighbour) {
            Complaint::factory()->forCustomer($customer)->count(2)->create();
            Complaint::factory()->forCustomer($neighbour)->count(3)->create();
        });

        $this->actingAs($user)->getJson('/api/v1/complaints')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_a_customer_cannot_open_a_neighbours_complaint(): void
    {
        [$user] = $this->customerAccount($this->ourBranch);
        [, $neighbour] = $this->customerAccount($this->ourBranch);

        $theirs = SectorContext::withoutScope(
            fn () => Complaint::factory()->forCustomer($neighbour)->create()
        );

        // 404, not 403: confirming it exists tells them about someone else's
        // complaint.
        $this->actingAs($user)->getJson("/api/v1/complaints/{$theirs->id}")->assertNotFound();
    }

    public function test_a_franchise_owner_sees_only_their_branch(): void
    {
        SectorContext::withoutScope(function () {
            Complaint::factory()->count(2)->create(['branch_id' => $this->ourBranch->id]);
            Complaint::factory()->count(4)->create(['branch_id' => $this->theirBranch->id]);
        });

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->getJson('/api/v1/complaints')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_the_queue_reports_how_many_are_overdue(): void
    {
        SectorContext::withoutScope(function () {
            Complaint::factory()->overdue()->count(3)->create(['branch_id' => $this->ourBranch->id]);
            Complaint::factory()->count(2)->create([
                'branch_id' => $this->ourBranch->id, 'due_at' => now()->addDay(),
            ]);
            Complaint::factory()->closed()->create([
                'branch_id' => $this->ourBranch->id, 'due_at' => now()->subWeek(),
            ]);
        });

        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $this->actingAs($owner)->getJson('/api/v1/complaints')
            ->assertOk()
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('meta.live', 5)
            // The number that should drive somebody's morning. A closed
            // complaint whose clock ran out long ago is not one of them.
            ->assertJsonPath('meta.overdue', 3);
    }

    public function test_a_cleaner_cannot_assign_a_complaint(): void
    {
        $complaint = SectorContext::withoutScope(
            fn () => Complaint::factory()->create(['branch_id' => $this->ourBranch->id])
        );

        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($cleaner)->postJson("/api/v1/complaints/{$complaint->id}/assign", [
            'assignee_id' => $cleaner->id,
        ])->assertForbidden();
    }

    public function test_a_cleaner_cannot_resolve_someone_elses_complaint(): void
    {
        $mine = User::factory()->cleaner($this->ourBranch)->create();
        $theirs = User::factory()->cleaner($this->ourBranch)->create();

        $complaint = SectorContext::withoutScope(fn () => Complaint::factory()->create([
            'branch_id' => $this->ourBranch->id,
            'status' => ComplaintStatus::Assigned,
            'assigned_to' => $theirs->id,
        ]));

        $this->actingAs($mine)->postJson("/api/v1/complaints/{$complaint->id}/resolve", [
            'resolution' => 'I had a look and it seems fine.',
        ])->assertForbidden();
    }

    public function test_a_cleaner_can_resolve_the_one_given_to_them(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $complaint = SectorContext::withoutScope(fn () => Complaint::factory()->create([
            'branch_id' => $this->ourBranch->id,
            'status' => ComplaintStatus::Assigned,
            'assigned_to' => $cleaner->id,
        ]));

        $this->actingAs($cleaner)->postJson("/api/v1/complaints/{$complaint->id}/resolve", [
            'resolution' => 'Re-cleaned the car this evening.',
        ])->assertOk()->assertJsonPath('data.status.value', 'resolved');
    }

    public function test_a_cleaner_cannot_close_a_complaint(): void
    {
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $complaint = SectorContext::withoutScope(fn () => Complaint::factory()->create([
            'branch_id' => $this->ourBranch->id,
            'status' => ComplaintStatus::Resolved,
            'assigned_to' => $cleaner->id,
        ]));

        // Signing a complaint off is the customer's word to give, not the
        // person who did the work.
        $this->actingAs($cleaner)->postJson("/api/v1/complaints/{$complaint->id}/close")
            ->assertForbidden();
    }

    public function test_an_illegal_move_is_a_422_not_a_500(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();

        $complaint = SectorContext::withoutScope(fn () => Complaint::factory()->closed()->create([
            'branch_id' => $this->ourBranch->id,
        ]));

        // Refusing a transition is a bad request, and the message says which
        // move was refused rather than "something went wrong".
        $this->actingAs($owner)->postJson("/api/v1/complaints/{$complaint->id}/resolve", [
            'resolution' => 'Trying to resolve a closed one.',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_response_carries_the_whole_trail(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        [$user] = $this->customerAccount($this->ourBranch);

        $created = $this->actingAs($user)->postJson('/api/v1/complaints', [
            'category' => ComplaintCategory::PoorQuality->value,
            'description' => 'Streaks left on the windscreen.',
        ])->assertCreated();

        $id = $created->json('data.id');
        $cleaner = User::factory()->cleaner($this->ourBranch)->create();

        $this->actingAs($owner)->postJson("/api/v1/complaints/{$id}/assign", [
            'assignee_id' => $cleaner->id,
        ])->assertOk();

        $response = $this->actingAs($owner)->getJson("/api/v1/complaints/{$id}")->assertOk();

        $this->assertSame(['raised', 'assigned'], array_column($response->json('data.events'), 'type'));
        $this->assertSame($cleaner->id, $response->json('data.assignee.id'));
    }

    public function test_a_complaint_cannot_be_handed_to_another_branch(): void
    {
        $owner = User::factory()->franchiseOwner($this->ourBranch)->create();
        $outsider = User::factory()->cleaner($this->theirBranch)->create();

        $complaint = SectorContext::withoutScope(
            fn () => Complaint::factory()->create(['branch_id' => $this->ourBranch->id])
        );

        // The scoped lookup makes the outsider invisible, so this is a 404
        // before it ever reaches the workflow's own check.
        $this->actingAs($owner)->postJson("/api/v1/complaints/{$complaint->id}/assign", [
            'assignee_id' => $outsider->id,
        ])->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Customer}
     */
    private function customerAccount(Branch $branch): array
    {
        $user = User::factory()->customer($branch)->create();

        $customer = SectorContext::withoutScope(fn () => Customer::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]));

        return [$user, $customer];
    }
}
