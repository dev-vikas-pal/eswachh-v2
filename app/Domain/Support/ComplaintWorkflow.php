<?php

namespace App\Domain\Support;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Numbering\SeriesNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every legal move a complaint can make, in one place.
 *
 * The rule is that a status never changes without an entry in the trail
 * alongside it, in the same transaction. Scatter the transitions across
 * controllers and the two drift apart within a month, which is how v1 ended up
 * with complaints whose history did not match their state.
 */
class ComplaintWorkflow
{
    /**
     * @param  array{category?: ComplaintCategory, priority?: ComplaintPriority, description: string, vehicle_id?: ?string, subscription_id?: ?string}  $input
     */
    public function raise(Customer $customer, array $input, ?User $actor = null): Complaint
    {
        $category = $input['category'] ?? ComplaintCategory::Other;
        $priority = $input['priority'] ?? ComplaintPriority::Normal;

        return DB::transaction(function () use ($customer, $input, $category, $priority, $actor) {
            $complaint = Complaint::create([
                'branch_id' => $customer->branch_id,
                'reference' => SeriesNumber::next($customer->branch_id, Complaint::class, 'reference', 'CMP'),
                'customer_id' => $customer->id,
                'vehicle_id' => $input['vehicle_id'] ?? $this->onlyVehicleOf($customer),
                'subscription_id' => $input['subscription_id'] ?? null,
                'category' => $category,
                'priority' => $priority,
                'description' => $input['description'],
                'status' => ComplaintStatus::Open,
                // Fixed at the moment of raising. If the policy changes next
                // month, the promise made to this customer does not.
                'due_at' => $this->dueAt($category, $priority),
            ]);

            $this->record($complaint, 'raised', null, ComplaintStatus::Open, $input['description'], $actor);

            return $complaint;
        });
    }

    public function assign(Complaint $complaint, User $assignee, ?User $actor = null, ?string $note = null): Complaint
    {
        $this->guard($complaint, ComplaintStatus::Assigned);

        if ($assignee->branch_id !== $complaint->branch_id && ! $assignee->seesAllBranches()) {
            // Handing a complaint to somebody in another franchise would make
            // it invisible to them and unactionable. Fail loudly.
            throw new LogicException('That person does not work in this branch.');
        }

        return DB::transaction(function () use ($complaint, $assignee, $actor, $note) {
            $from = $complaint->status;

            $complaint->forceFill([
                'assigned_to' => $assignee->id,
                'assigned_at' => now(),
                'status' => ComplaintStatus::Assigned,
            ])->save();

            $this->record(
                $complaint, 'assigned', $from, ComplaintStatus::Assigned,
                $note ?? "Assigned to {$assignee->name}.", $actor
            );

            return $complaint;
        });
    }

    public function addNote(Complaint $complaint, string $note, ?User $actor = null): Complaint
    {
        // A note never moves the status, so there is no guard: anyone who can
        // see the complaint can say something about it.
        $this->record($complaint, 'note', $complaint->status, $complaint->status, $note, $actor);

        return $complaint;
    }

    public function resolve(Complaint $complaint, string $resolution, ?User $actor = null): Complaint
    {
        $this->guard($complaint, ComplaintStatus::Resolved);

        return DB::transaction(function () use ($complaint, $resolution, $actor) {
            $from = $complaint->status;

            $complaint->forceFill([
                'status' => ComplaintStatus::Resolved,
                'resolved_at' => now(),
                'resolved_by' => $actor?->id,
                // Kept for the list view. The trail keeps every earlier one, so
                // overwriting this loses nothing.
                'resolution_note' => $resolution,
            ])->save();

            $this->record($complaint, 'resolved', $from, ComplaintStatus::Resolved, $resolution, $actor);

            return $complaint;
        });
    }

    /**
     * The customer came back unsatisfied.
     *
     * The clock restarts, because the promise was not kept. It goes back to the
     * person who had it rather than to nobody, so it does not silently fall off
     * the queue.
     */
    public function reopen(Complaint $complaint, string $reason, ?User $actor = null): Complaint
    {
        if (! in_array($complaint->status, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true)) {
            throw new LogicException('Only a resolved or closed complaint can be reopened.');
        }

        return DB::transaction(function () use ($complaint, $reason, $actor) {
            $from = $complaint->status;

            $complaint->forceFill([
                'status' => ComplaintStatus::Assigned,
                'resolved_at' => null,
                'resolved_by' => null,
                'closed_at' => null,
                'closed_by' => null,
                'reopened_count' => $complaint->reopened_count + 1,
                'due_at' => $this->dueAt($complaint->category, $complaint->priority),
            ])->save();

            $this->record($complaint, 'reopened', $from, ComplaintStatus::Assigned, $reason, $actor);

            return $complaint;
        });
    }

    public function close(Complaint $complaint, ?User $actor = null, ?string $note = null): Complaint
    {
        $this->guard($complaint, ComplaintStatus::Closed);

        return DB::transaction(function () use ($complaint, $actor, $note) {
            $from = $complaint->status;

            $complaint->forceFill([
                'status' => ComplaintStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor?->id,
            ])->save();

            $this->record($complaint, 'closed', $from, ComplaintStatus::Closed, $note, $actor);

            return $complaint;
        });
    }

    // ---------------------------------------------------------------- private

    private function guard(Complaint $complaint, ComplaintStatus $next): void
    {
        if (! $complaint->status->allows($next)) {
            throw new LogicException(
                "A {$complaint->status->value} complaint cannot become {$next->value}."
            );
        }
    }

    private function record(
        Complaint $complaint,
        string $type,
        ?ComplaintStatus $from,
        ?ComplaintStatus $to,
        ?string $note,
        ?User $actor,
    ): void {
        ComplaintEvent::create([
            'complaint_id' => $complaint->id,
            'branch_id' => $complaint->branch_id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            // Null means the system did it. Not "we do not know".
            'actor_id' => $actor?->id,
        ]);
    }

    private function dueAt(ComplaintCategory $category, ComplaintPriority $priority): Carbon
    {
        $hours = $category->responseHours() * $priority->clockFactor();

        return Carbon::now()->addMinutes((int) round($hours * 60));
    }

    /**
     * If the customer has exactly one car, the complaint is obviously about it.
     * With two, guessing would be worse than leaving it blank.
     */
    private function onlyVehicleOf(Customer $customer): ?string
    {
        $vehicles = Vehicle::query()->where('customer_id', $customer->id)->limit(2)->pluck('id');

        return $vehicles->count() === 1 ? $vehicles->first() : null;
    }
}
