<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,

            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],

            // Derived on the server so every client agrees on what expired
            // means, rather than each one comparing dates its own way.
            'is_expired' => $this->isExpired(),

            'period' => [
                'start' => $this->period_start?->toDateString(),
                'end' => $this->period_end?->toDateString(),
            ],

            // Sent as paise and as a formatted string: the client never does
            // currency arithmetic.
            'amount' => [
                'paise' => $this->amount_paise,
                'formatted' => '₹'.number_format($this->amount_paise / 100, 2),
            ],
            'paid' => [
                'paise' => $this->paid_amount_paise,
                'formatted' => '₹'.number_format($this->paid_amount_paise / 100, 2),
            ],

            /*
             * The last payment against this plan, shown where v1 put it: on
             * the order itself. It is read from the payment record rather than
             * stored twice, so the figure here and the figure on the payments
             * screen cannot disagree.
             *
             * v1's "Order Type" is derived rather than a field of its own -
             * online means it came through the gateway, offline means somebody
             * recorded it by hand.
             */
            'last_payment' => $this->whenLoaded('lastPayment', fn () => $this->lastPayment ? [
                'id' => $this->lastPayment->id,
                'invoice_number' => $this->lastPayment->invoice_number,
                'amount' => $this->lastPayment->amount(),
                'method' => $this->lastPayment->method,
                'reference' => $this->lastPayment->reference,
                'paid_at' => $this->lastPayment->paid_at?->toDateString(),
                'order_type' => $this->lastPayment->gateway === 'manual' ? 'offline' : 'online',
                'recorded_by_hand' => $this->lastPayment->wasSetByHand(),
            ] : null),

            'cloth' => [
                'enabled' => $this->cloth_service,
                'balance' => $this->cloth_balance,
            ],

            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'registration' => $this->vehicle->registration,
                'vehicle_model_id' => $this->vehicle->vehicle_model_id,
                'model' => $this->vehicle->relationLoaded('model')
                    ? $this->vehicle->model?->name
                    : null,
                'cleaner' => $this->vehicle->relationLoaded('cleaner') && $this->vehicle->cleaner
                    ? ['id' => $this->vehicle->cleaner->id, 'name' => $this->vehicle->cleaner->name]
                    : null,
            ]),

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),

            /*
             * The ids as well as the names, so an edit form can prefill itself
             * from one request instead of fetching the plan a second time.
             */
            'package_id' => $this->package_id,
            'service_type_id' => $this->service_type_id,
            'duration_id' => $this->duration_id,
            'cloth_bundle_id' => $this->cloth_bundle_id,

            'package' => $this->whenLoaded('package', fn () => $this->package?->name),
            'service_type' => $this->whenLoaded('serviceType', fn () => $this->serviceType?->name),
            'duration' => $this->whenLoaded('duration', fn () => $this->duration?->name),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
