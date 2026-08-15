<?php

namespace Tests\Feature\Support;

use App\Domain\Support\ComplaintWorkflow;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class ComplaintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ComplaintWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->branch = Branch::factory()->create(['code' => 'GN1']);
        $this->workflow = app(ComplaintWorkflow::class);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    public function test_raising_a_complaint_starts_the_trail(): void
    {
        $complaint = BranchContext::withoutScope(
            fn () => $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::NotCleaned,
                'description' => 'The car was not touched on Tuesday.',
            ])
        );

        $this->assertSame(ComplaintStatus::Open, $complaint->status);
        $this->assertStringStartsWith('GN1/CMP/', $complaint->reference);

        // A status never exists without an entry explaining how it got there.
        $events = ComplaintEvent::query()->where('complaint_id', $complaint->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame('raised', $events->first()->type);
        $this->assertSame('open', $events->first()->to_status);
    }

    public function test_the_clock_reflects_how_urgent_the_category_is(): void
    {
        $notCleaned = BranchContext::withoutScope(
            fn () => $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::NotCleaned,
                'description' => 'Nobody came.',
            ])
        );

        $billing = BranchContext::withoutScope(
            fn () => $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::Billing,
                'description' => 'Charged twice.',
            ])
        );

        // A car that was not cleaned today can still be cleaned today, so it
        // gets the tighter clock. Billing needs a statement checked.
        $this->assertTrue($notCleaned->due_at->lessThan($billing->due_at));
    }

    public function test_high_priority_halves_the_clock_without_flattening_it(): void
    {
        $normal = BranchContext::withoutScope(
            fn () => $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::Timing,
                'priority' => ComplaintPriority::Normal,
                'description' => 'They come too late.',
            ])
        );

        $urgent = BranchContext::withoutScope(
            fn () => $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::Timing,
                'priority' => ComplaintPriority::High,
                'description' => 'They come too late.',
            ])
        );

        $this->assertTrue($urgent->due_at->lessThan($normal->due_at));
    }

    public function test_the_promise_made_at_raising_does_not_move_later(): void
    {
        $complaint = BranchContext::withoutScope(
            fn () => $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::NotCleaned,
                'description' => 'Missed again.',
            ])
        );

        $promised = $complaint->due_at->copy();

        BranchContext::withoutScope(function () use ($complaint) {
            $this->workflow->assign($complaint, $this->cleaner());
            $this->workflow->addNote($complaint, 'Spoke to the customer.');
        });

        // Working on a complaint does not buy more time. v1 had no clock at
        // all, so a complaint could sit untouched with nothing to show it.
        $this->assertTrue($promised->equalTo($complaint->fresh()->due_at));
    }

    public function test_a_complaint_cannot_skip_from_closed_to_resolved(): void
    {
        $complaint = BranchContext::withoutScope(
            fn () => Complaint::factory()->closed()->forCustomer($this->customer())->create()
        );

        $this->expectException(LogicException::class);

        BranchContext::withoutScope(
            fn () => $this->workflow->resolve($complaint, 'Sorted it.')
        );
    }

    public function test_a_complaint_cannot_be_assigned_to_another_branch(): void
    {
        $complaint = BranchContext::withoutScope(
            fn () => Complaint::factory()->forCustomer($this->customer())->create()
        );

        $outsider = User::factory()->cleaner(Branch::factory()->create())->create();

        // They would never see it, so the assignment would silently strand it.
        $this->expectException(LogicException::class);

        BranchContext::withoutScope(
            fn () => $this->workflow->assign($complaint, $outsider)
        );
    }

    public function test_reopening_restarts_the_clock_and_counts(): void
    {
        BranchContext::withoutScope(function () {
            $complaint = $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::PoorQuality,
                'description' => 'Streaks all over the bonnet.',
            ]);

            $cleaner = $this->cleaner();
            $this->workflow->assign($complaint, $cleaner);
            $this->workflow->resolve($complaint, 'Re-cleaned it.');

            Carbon::setTestNow(Carbon::now()->addHours(30));

            $this->workflow->reopen($complaint, 'Still streaky.');

            $complaint->refresh();

            $this->assertSame(ComplaintStatus::Assigned, $complaint->status);
            $this->assertSame(1, $complaint->reopened_count);
            // The promise was not kept, so the clock starts again rather than
            // leaving it permanently overdue.
            $this->assertTrue($complaint->due_at->isFuture());
            $this->assertNull($complaint->resolved_at);
            // And it goes back to whoever had it, not to nobody.
            $this->assertSame($cleaner->id, $complaint->assigned_to);

            Carbon::setTestNow();
        });
    }

    public function test_every_move_leaves_an_entry_in_order(): void
    {
        BranchContext::withoutScope(function () {
            $complaint = $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::NotCleaned,
                'description' => 'Not done.',
            ]);

            $this->workflow->assign($complaint, $this->cleaner());
            $this->workflow->addNote($complaint, 'Called the customer.');
            $this->workflow->resolve($complaint, 'Cleaned it this evening.');
            $this->workflow->reopen($complaint, 'Still dirty.');
            $this->workflow->resolve($complaint, 'Done again, customer happy.');
            $this->workflow->close($complaint);

            $types = $complaint->events()->pluck('type')->all();

            $this->assertSame(
                ['raised', 'assigned', 'note', 'resolved', 'reopened', 'resolved', 'closed'],
                $types
            );
        });
    }

    public function test_a_trail_entry_cannot_be_edited_or_deleted(): void
    {
        BranchContext::withoutScope(function () {
            $complaint = $this->workflow->raise($this->customer(), [
                'category' => ComplaintCategory::Other,
                'description' => 'Something happened.',
            ]);

            $event = $complaint->events()->first();

            // v1 kept one resolution note that whoever wrote last overwrote,
            // so nobody could say what had been promised earlier.
            try {
                $event->update(['note' => 'Actually, nothing happened.']);
                $this->fail('A trail entry was allowed to be edited.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }

            try {
                $event->delete();
                $this->fail('A trail entry was allowed to be deleted.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }
        });
    }

    public function test_overdue_is_derived_rather_than_stored(): void
    {
        BranchContext::withoutScope(function () {
            $complaint = Complaint::factory()->overdue()->forCustomer($this->customer())->create();

            $this->assertTrue($complaint->isOverdue());

            // Resolving it stops the clock, without any stored flag needing to
            // be cleared - which is the failure mode of a stored flag.
            $this->workflow->resolve($complaint, 'Dealt with.');

            $this->assertFalse($complaint->fresh()->isOverdue());
        });
    }

    public function test_the_overdue_scope_and_the_model_agree(): void
    {
        BranchContext::withoutScope(function () {
            $customer = $this->customer();
            Complaint::factory()->overdue()->forCustomer($customer)->count(2)->create();
            Complaint::factory()->forCustomer($customer)->create(['due_at' => now()->addDay()]);
            Complaint::factory()->closed()->forCustomer($customer)->create(['due_at' => now()->subWeek()]);

            $byScope = Complaint::query()->overdue()->get();
            $byModel = Complaint::query()->get()->filter(fn (Complaint $c) => $c->isOverdue());

            // A closed complaint is not overdue however long ago its clock ran
            // out, and the two ways of asking must never disagree.
            $this->assertSame(2, $byScope->count());
            $this->assertEqualsCanonicalizing($byScope->pluck('id')->all(), $byModel->pluck('id')->all());
        });
    }

    public function test_a_complaint_lands_on_the_only_car_the_customer_has(): void
    {
        BranchContext::withoutScope(function () {
            $customer = $this->customer();
            $vehicle = Vehicle::factory()->forCustomer($customer)->create();

            $complaint = $this->workflow->raise($customer, [
                'category' => ComplaintCategory::NotCleaned,
                'description' => 'Not cleaned.',
            ]);

            $this->assertSame($vehicle->id, $complaint->vehicle_id);
        });
    }

    public function test_with_two_cars_it_does_not_guess(): void
    {
        BranchContext::withoutScope(function () {
            $customer = $this->customer();
            Vehicle::factory()->forCustomer($customer)->count(2)->create();

            $complaint = $this->workflow->raise($customer, [
                'category' => ComplaintCategory::NotCleaned,
                'description' => 'Not cleaned.',
            ]);

            // Guessing wrong is worse than leaving it blank for someone to ask.
            $this->assertNull($complaint->vehicle_id);
        });
    }

    // ---------------------------------------------------------------- helpers

    private function customer(): Customer
    {
        return Customer::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function cleaner(): User
    {
        return User::factory()->cleaner($this->branch)->create();
    }
}
