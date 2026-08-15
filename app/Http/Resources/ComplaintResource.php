<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Complaint
 */
class ComplaintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'category' => ['value' => $this->category->value, 'label' => $this->category->label()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'description' => $this->description,

            // Derived, not stored: a stored overdue flag is right only until
            // the next minute passes.
            'is_overdue' => $this->isOverdue(),
            'due_at' => $this->due_at?->toIso8601String(),
            'age_hours' => $this->ageInHours(),
            'reopened_count' => $this->reopened_count,

            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'assigned_at' => $this->assigned_at?->toIso8601String(),

            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'registration' => $this->vehicle->registration,
            ] : null),

            // The trail, oldest first. Only sent when asked for: a list of
            // fifty complaints does not need every note on every one.
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'note' => $e->note,
                'actor' => $e->relationLoaded('actor') && $e->actor
                    ? ['id' => $e->actor->id, 'name' => $e->actor->name]
                    : null,
                'created_at' => $e->created_at?->toIso8601String(),
            ])),

            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
