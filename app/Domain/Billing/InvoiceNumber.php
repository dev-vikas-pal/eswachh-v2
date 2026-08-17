<?php

namespace App\Domain\Billing;

use App\Models\Payment;
use App\Support\Numbering\SeriesNumber;

/**
 * Issues the next invoice number.
 *
 * One unbroken run per financial year for the whole business. It used to run
 * per branch; sectors replaced branches and are the wrong key for this, because
 * somebody covering three of them would have their invoices split across three
 * runs. Gaps in an invoice series are the sort of thing that gets questioned at
 * audit, so the number is issued only once the money is captured, never when a
 * payment is merely attempted.
 *
 * The mechanics live in SeriesNumber, which complaint references share. This
 * class stays because "invoice number" is a thing the business says, and the
 * rule about when one is issued belongs next to the name.
 */
class InvoiceNumber
{
    public static function next(): string
    {
        return SeriesNumber::next(Payment::class, 'invoice_number', 'INV');
    }
}
