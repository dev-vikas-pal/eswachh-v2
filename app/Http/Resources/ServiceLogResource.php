<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ServiceLog
 */
class ServiceLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serviced_on' => $this->serviced_on?->toDateString(),
            'serviced_at' => $this->serviced_at?->toIso8601String(),
            'outcome' => ['value' => $this->outcome->value, 'label' => $this->outcome->label()],
            // Sent separately so the client does not have to know which
            // outcomes are our failure and which are not.
            'was_cleaned' => $this->outcome->wasCleaned(),
            'our_fault' => $this->outcome->isOurFault(),
            'note' => $this->note,
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'registration' => $this->vehicle->registration,
            ]),
            'cleaner' => $this->whenLoaded('cleaner', fn () => $this->cleaner ? [
                'id' => $this->cleaner->id,
                'name' => $this->cleaner->name,
            ] : null),
        ];
    }
}
