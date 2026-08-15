<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'purpose' => $this->purpose->value,
            'purpose_label' => $this->purpose->label(),

            // Sent in both forms: paise for arithmetic, rupees for display, so
            // the client never divides by 100 itself and never rounds twice.
            'amount_paise' => (int) $this->amount_paise,
            'amount' => $this->amount(),
            'currency' => $this->currency,

            'method' => $this->method,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at?->toIso8601String(),

            // The gateway's own ids, shown so support can quote them to
            // Razorpay without anyone opening the database.
            'gateway_order_id' => $this->gateway_order_id,
            'gateway_payment_id' => $this->gateway_payment_id,

            'recorded_by_hand' => $this->wasSetByHand(),
            'notes' => $this->notes,

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'subscription_id' => $this->subscription_id,
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
