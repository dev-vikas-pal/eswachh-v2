<?php

namespace App\Domain\Billing;

use App\Models\Payment;
use App\Support\Numbering\SeriesNumber;

/**
 * Issues the next invoice number for a branch.
 *
 * Numbers run per branch per financial year, so each branch has an unbroken run
 * of its own that an accountant can follow. Gaps in an invoice series are the
 * sort of thing that gets questioned at audit, so the number is issued only
 * once the money is captured, never when a payment is merely attempted.
 *
 * The mechanics live in SeriesNumber, which complaint references share. This
 * class stays because "invoice number" is a thing the business says, and the
 * rule about when one is issued belongs next to the name.
 */
class InvoiceNumber
{
    public static function next(?string $branchId): string
    {
        return SeriesNumber::next($branchId, Payment::class, 'invoice_number', 'INV');
    }
}
