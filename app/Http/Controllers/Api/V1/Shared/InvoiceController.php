<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Domain\Billing\Invoice;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Http\RestrictsToOwnRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A receipt for one payment.
 *
 * v1 had an invoice screen and v2 had the settings for one - a prefix and a
 * footer note - with nothing rendering them. This closes that: the office
 * prints it for the customer, and the customer prints their own.
 *
 * The document itself is built by Domain\Billing\Invoice, which the public
 * receipt page also uses - a customer who never made an account gets the same
 * paper from a link, and it has to say the same thing.
 */
class InvoiceController extends Controller
{
    use RestrictsToOwnRecords;

    public function __invoke(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view.invoice');

        // Their own receipt only. The sector is not enough of a filter for a
        // customer: every other customer of the franchise is inside it.
        abort_unless($this->ownsRecord($request, $payment->customer_id), 404);

        // Nothing was paid, so there is nothing to give a receipt for. A
        // receipt for an abandoned checkout is a document saying money changed
        // hands when it did not.
        abort_unless($payment->status === PaymentStatus::Captured, 404);

        return response()->json(['data' => Invoice::for($payment)]);
    }
}
