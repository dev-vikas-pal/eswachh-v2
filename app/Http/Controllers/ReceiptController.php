<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Invoice;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Tenancy\SectorContext;
use Illuminate\Contracts\View\View;

/**
 * The receipt a customer opens from the message we sent them.
 *
 * Not part of the API and not behind a session, on purpose. Most customers
 * never make an account - the whole public renewal page exists because of that
 * - so a receipt they can only reach by signing in is a receipt they will never
 * see, and "please send me the bill" becomes a phone call to the office.
 *
 * What protects it is the signature, checked by the middleware before any of
 * this runs. The link is generated with the application key and Laravel refuses
 * it if a single character is altered, so the id in it cannot be edited into
 * somebody else's. That does make the link itself the credential: anyone the
 * customer forwards it to can read the receipt. That is the same bargain as
 * emailing a PDF invoice, and the alternative - a password on a receipt - is
 * not one customers would use.
 *
 * Deliberately never expires. The message it came in is permanent, and a link
 * that stops working in ninety days turns into a support call at exactly the
 * moment somebody needs the document for their own records.
 */
class ReceiptController extends Controller
{
    /**
     * The id is taken as a string and resolved here, not by route model
     * binding.
     *
     * Binding resolves through the sector scope, and nobody is signed in on
     * this page - so the scope covers nothing and every receipt link answered
     * "not found", including the valid ones. The relations the document is
     * built from would have come back empty for the same reason, which is the
     * quieter half of the same mistake: the page renders, with no name and no
     * address on it.
     */
    public function __invoke(string $payment): View
    {
        return SectorContext::withoutScope(function () use ($payment) {
            $found = Payment::query()->find($payment);

            abort_unless($found !== null, 404);

            // A receipt for an abandoned checkout is a document saying money
            // changed hands when it did not.
            abort_unless($found->status === PaymentStatus::Captured, 404);

            return view('receipt', ['invoice' => Invoice::for($found)]);
        });
    }
}
