<?php

namespace App\Domain\Billing;

use App\Models\Payment;
use App\Support\Settings\SiteSettings;

/**
 * One receipt, as data.
 *
 * Built here rather than in a controller because there are now two ways to ask
 * for the same document - the office and the customer's own pages read it as
 * JSON, and a customer with no account opens it as a page from a link we sent
 * them - and a receipt that says two different things depending on which door
 * it came through is not a receipt.
 *
 * Everything about the business is read at the moment it is asked for, so a
 * receipt printed today shows today's address. Everything about the payment -
 * the amount, the invoice number, the date - is the payment's own and must
 * never move.
 */
class Invoice
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Payment $payment): array
    {
        $payment->loadMissing([
            'customer.sector', 'customer.society',
            'subscription.vehicle', 'subscription.package', 'subscription.duration',
        ]);

        return [
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
                'address' => self::addressOf($payment),
            ],

            /*
             * One line, because one payment buys one thing. If part payments
             * are ever taken this becomes a list, which is why it is already
             * shaped as one.
             */
            'lines' => [[
                'description' => self::describe($payment),
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
        ];
    }

    private static function describe(Payment $payment): string
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

    private static function addressOf(Payment $payment): string
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
