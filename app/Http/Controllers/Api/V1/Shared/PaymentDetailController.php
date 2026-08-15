<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Http\RestrictsToOwnRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One payment, in full.
 *
 * Everything support needs to answer "what happened to my money" without
 * anybody opening the database: what was bought, who bought it, what the
 * gateway called it, who touched it by hand, and what else has been paid on the
 * same plan.
 *
 * The sibling payments matter more than they look. Almost every argument about
 * a payment is really about two of them - a duplicate charge, a renewal that
 * looks like it was taken twice - and answering it means seeing the plan's
 * whole history on one screen rather than filtering a list and hoping.
 */
class PaymentDetailController extends Controller
{
    use RestrictsToOwnRecords;

    public function __invoke(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view.payment');

        // Branch scoping got us this far; a customer's neighbours share their
        // branch, so their payments have to be excluded here as well.
        abort_unless($this->ownsRecord($request, $payment->customer_id), 404);

        $payment->load([
            'customer.sector:id,name',
            'subscription.vehicle.model',
            'subscription.package',
            'subscription.serviceType',
            'subscription.duration',
            'verifiedBy:id,name',
        ]);

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'invoice_number' => $payment->invoice_number,

                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'purpose' => $payment->purpose->value,
                'purpose_label' => $payment->purpose->label(),

                'amount' => $payment->amount(),
                'amount_paise' => (int) $payment->amount_paise,
                'amount_formatted' => '₹'.number_format($payment->amount(), 2),
                'currency' => $payment->currency,

                /*
                 * v1's "order type" was a column somebody set. Here it is
                 * derived from how the payment was actually taken, so it cannot
                 * disagree with the record it describes.
                 */
                'channel' => $payment->gateway === 'manual' ? 'offline' : 'online',
                'method' => $payment->method,
                'recorded_by_hand' => $payment->wasSetByHand(),

                'gateway' => [
                    'name' => $payment->gateway,
                    'order_id' => $payment->gateway_order_id,
                    'payment_id' => $payment->gateway_payment_id,
                    'reference' => $payment->reference,
                ],

                'timeline' => $this->timelineFor($payment),

                'verified_by' => $payment->verifiedBy?->name,
                'verified_at' => $payment->verified_at?->toIso8601String(),
                'notes' => $payment->notes,

                'customer' => $payment->customer ? [
                    'id' => $payment->customer->id,
                    'name' => $payment->customer->name,
                    'phone' => $payment->customer->phone,
                    'sector' => $payment->customer->sector?->name,
                ] : null,

                'subscription' => $payment->subscription ? [
                    'id' => $payment->subscription->id,
                    'sequence' => $payment->subscription->sequence,
                    'status' => $payment->subscription->status->value,
                    'registration' => $payment->subscription->vehicle?->registration,
                    'car_model' => $payment->subscription->vehicle?->model?->name,
                    'package' => $payment->subscription->package?->name,
                    'service_type' => $payment->subscription->serviceType?->name,
                    'duration' => $payment->subscription->duration?->name,
                    'period' => [
                        'start' => $payment->subscription->period_start?->toDateString(),
                        'end' => $payment->subscription->period_end?->toDateString(),
                    ],
                    'amount' => $payment->subscription->amount_paise / 100,
                    'paid' => $payment->subscription->paid_amount_paise / 100,
                    'outstanding' => max(0, $payment->subscription->amount_paise - $payment->subscription->paid_amount_paise) / 100,
                ] : null,

                'others_on_this_plan' => $this->siblingsOf($payment),
                'has_receipt' => $payment->status === PaymentStatus::Captured && (bool) $payment->invoice_number,
            ],
        ]);
    }

    /**
     * What happened, in order.
     *
     * Read off the payment's own columns rather than a separate log, because a
     * log that can disagree with the row it describes is worse than no log.
     *
     * @return array<int, array<string, string|null>>
     */
    private function timelineFor(Payment $payment): array
    {
        $events = [[
            'at' => $payment->created_at?->toIso8601String(),
            'what' => 'Payment opened',
            'detail' => $payment->gateway === 'manual'
                ? 'Recorded at the office.'
                : 'Sent to '.$payment->gateway.' as order '.($payment->gateway_order_id ?: 'unknown').'.',
        ]];

        if ($payment->paid_at) {
            $events[] = [
                'at' => $payment->paid_at->toIso8601String(),
                'what' => 'Money received',
                'detail' => $payment->gateway_payment_id
                    ? 'Confirmed by the gateway as '.$payment->gateway_payment_id.'.'
                    : 'Taken at the office.',
            ];
        }

        if ($payment->verified_at) {
            $events[] = [
                'at' => $payment->verified_at->toIso8601String(),
                'what' => 'Checked by hand',
                'detail' => ($payment->verifiedBy?->name ?? 'Somebody').' confirmed this payment.',
            ];
        }

        if ($payment->status === PaymentStatus::Failed) {
            $events[] = [
                'at' => $payment->updated_at?->toIso8601String(),
                'what' => 'Failed',
                'detail' => 'The gateway did not complete this payment. Nothing was charged.',
            ];
        }

        if ($payment->status === PaymentStatus::Refunded) {
            $events[] = [
                'at' => $payment->updated_at?->toIso8601String(),
                'what' => 'Refunded',
                'detail' => 'The money was returned.',
            ];
        }

        return $events;
    }

    /**
     * Every other payment against the same plan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function siblingsOf(Payment $payment): array
    {
        if (! $payment->subscription_id) {
            return [];
        }

        return Payment::query()
            ->where('subscription_id', $payment->subscription_id)
            ->whereKeyNot($payment->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Payment $other) => [
                'id' => $other->id,
                'invoice_number' => $other->invoice_number,
                'status' => $other->status->value,
                'status_label' => $other->status->label(),
                'amount' => $other->amount(),
                'paid_at' => $other->paid_at?->toDateString(),
                'purpose_label' => $other->purpose->label(),
            ])
            ->all();
    }
}
