<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed in user, as the front end needs them.
 *
 * Abilities are included so the SPA can decide what to render. They are a
 * convenience for the interface only - every request is still authorised on
 * the server, because anything the browser is told can be edited by the
 * browser.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => [
                'value' => $this->role?->value,
                'label' => $this->role?->label(),
            ],
            'abilities' => $this->abilities(),
            'sees_all_sectors' => $this->seesAllSectors(),

            // Sent with the session so the interface can draw itself correctly
            // on the very first paint, rather than rendering the default and
            // then rearranging once a second request comes back.
            'settings' => $this->settings(),

            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
        ];
    }
}
