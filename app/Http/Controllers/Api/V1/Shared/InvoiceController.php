<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Http\RestrictsToOwnRecords;
use App\Support\Settings\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A receipt for one payment.
 *
 * v1 had an invoice screen and v2 had the settings for one - a prefix and a
 * footer note - with nothing rendering them. This closes that: the office
 * prints it for the customer, and the customer prints their own.
 *
 * Everything on it is read at the moment it is asked for rather than stored
 * with the payment. That is deliberate for the business details, which should
 * show the current address; it is deliberately *not* true of the amount, the
 * invoice number or the date, which are the payment's own and must never move.
 */
class InvoiceController extends Controller
{
    use RestrictsToOwnRecords;

    public function __invoke(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view.invoice');

        // Their own receipt only. The branch is not enough of a filter for a
        // customer: every other customer of the franchise is inside it.
        abort_unless($this->ownsRecord($request, $payment->customer_id), 404);

        // Nothing was paid, so there is nothing to give a receipt for. A
        // receipt for an abandoned checkout is a document saying money changed
        // hands when it did not.
        abort_unless($payment->status === PaymentStatus::Captured, 404);

        $payment->load(['customer.sector', 'customer.society', 'subscription.vehicle', 'subscription.package', 'subscription.duration']);

        return response()->json([
            'data' => [
                'number' => $payment->invoice_number,
                'issued_on' => $payment->paid_at?->toDateString(),

                'from' => [
                    'name' => SiteSettings::get('legal_name') ?: SiteSettings::get('business_name'),
                    'address' => SiteSettings::get('address'),
                    'gstin' => SiteSettings::get('gstin'),
                    'phone' => SiteSettings::get('contact_phone'),
                    'email' => SiteSettings::get('contact_email'),
                ],

                'to' => [
                    'name' => $payment->customer?->name,
                    'phone' => $payment->customer?->phone,
                    'address' => $this->addressOf($payment),
                ],

                /*
                 * One line, because one payment buys one thing. If part
                 * payments are ever taken this becomes a list, which is why it
                 * is already shaped as one.
                 */
                'lines' => [[
                    'description' => $this->describe($payment),
                    'period' => $payment->subscription ? [
                        'start' => $payment->subscription->period_start?->toDateString(),
                        'end' => $payment->subscription->period_end?->toDateString(),
                    ] : null,
                    'amount' => $payment->amount(),
                ]],

                'total' => $payment->amount(),
                'total_formatted' => '₹'.number_format($payment->amount(), 2),

                'method' => $payment->method,
                'reference' => $payment->gateway_payment_id ?: $payment->reference,
                'paid_by_hand' => $payment->wasSetByHand(),

                'footer' => SiteSettings::get('invoice_footer'),
            ],
        ]);
    }

    private function describe(Payment $payment): string
    {
        $plan = $payment->subscription;

        if (! $plan) {
            return $payment->purpose->label();
        }

        return implode(' · ', array_filter([
            $payment->purpose->label(),
            $plan->vehicle?->registration,
            $plan->package?->name,
            $plan->duration?->name,
        ]));
    }

    private function addressOf(Payment $payment): string
    {
        $customer = $payment->customer;

        if (! $customer) {
            return '';
        }

        return implode(', ', array_filter([
            $customer->house_no,
            $customer->society?->name,
            $customer->sector?->name,
            $customer->address,
        ]));
    }
}
