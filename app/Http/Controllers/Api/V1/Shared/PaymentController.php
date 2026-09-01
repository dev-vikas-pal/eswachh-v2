<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Domain\Billing\RazorpayGateway;
use App\Domain\Billing\RecordPayment;
use App\Domain\Billing\StartPayment;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Http\RestrictsToOwnRecords;
use App\Support\Http\FiltersBySector;
use App\Support\Http\SortsLists;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PaymentController extends Controller
{
    use RestrictsToOwnRecords;
    use FiltersBySector;
    use SortsLists;

    private const SORTABLE = [
        'invoice' => 'invoice_number',
        'paid' => 'paid_at',
        'amount' => 'amount_paise',
        'status' => 'status',
        'method' => 'method',
        'created' => 'created_at',
    ];

    /**
     * Payments the signed in user is allowed to see.
     *
     * The branch scope on the model does the filtering, so a franchise owner
     * cannot widen it with a query string. There is no branch_id filter here on
     * purpose.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('view.payment');

        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'search' => ['sometimes', 'string', 'max:100'],
            // Every payment against one plan, for the link from an order.
            'subscription_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()->with('customer');

        // Their own receipts, not the branch's takings.
        $this->restrictToOwnRecords($query, $request);

        if ($subscriptionId = $filters['subscription_id'] ?? null) {
            /*
             * Every period this car has had, not just the one asked about.
             *
             * A plan is a chain of periods and each renewal writes a new one, so
             * a payment sits on whichever link it bought. Filtering on the id
             * alone meant "Payments on this order", opened from the period the
             * car is on now, showed one payment - and the five before it, the
             * ones somebody is actually looking for when they ask "which was the
             * ₹949 in March", were filed under rows the office cannot see.
             *
             * No ownership check needed for the id itself: the sector scope and
             * the customer filter above already limit which rows can come back,
             * so an id from another sector simply matches nothing - and every
             * period in the chain belongs to the same customer by construction.
             */
            $query->whereIn('subscription_id', function ($periods) use ($subscriptionId) {
                $periods->select('id')->from('subscriptions')
                    ->whereIn('vehicle_id', function ($car) use ($subscriptionId) {
                        $car->select('vehicle_id')->from('subscriptions')->where('id', $subscriptionId);
                    });
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($from = $filters['from'] ?? null) {
            $query->whereDate('paid_at', '>=', Carbon::parse($from));
        }

        if ($to = $filters['to'] ?? null) {
            $query->whereDate('paid_at', '<=', Carbon::parse($to));
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        // The sector picker in the top bar.
        $this->applySectorFilter($query, $request, 'sector');

        $this->applySort($query, $request, self::SORTABLE, 'created');

        /*
         * Totalled before paginating, not after. paginate() puts a limit and
         * an offset on this same builder, and an offset applied to a one row
         * aggregate returns no rows at all - so a total taken afterwards reads
         * as zero from page two onward.
         */
        $capturedPaise = (int) (clone $query)->revenue()->sum('amount_paise');

        $payments = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => PaymentResource::collection($payments->items()),
            'meta' => [
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                // Revenue for the same filter, so the total on screen always
                // agrees with the rows on screen.
                'total_captured_paise' => $capturedPaise,
            ] + $this->sortMeta($request, self::SORTABLE, 'created'),
        ]);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('view.payment');

        // Somebody else's receipt is a 404, not a 403: a customer's branch
        // holds every other customer of the franchise.
        abort_unless($this->ownsRecord($request, $payment->customer_id), 404);

        return new PaymentResource($payment->load('customer', 'subscription'));
    }

    /**
     * Open a payment for a subscription and hand back what checkout needs.
     */
    public function start(Request $request, Subscription $subscription, StartPayment $starter): JsonResponse
    {
        // Route model binding already applied the branch scope, so an
        // out-of-branch subscription arrives here as a 404 and never reaches
        // this line. A customer's neighbours share that branch, though, so
        // their plans have to be excluded here as well.
        abort_unless($this->ownsRecord($request, $subscription->customer_id), 404);

        $result = $starter->forSubscription($subscription);

        return response()->json([
            'data' => $result['checkout'],
        ], 201);
    }

    /**
     * Where the gateway sends the customer back to.
     *
     * Unauthenticated on purpose: Razorpay posts here server to server as well
     * as through the browser, and there is no session on that request. The
     * signature is the authentication - which is why it is checked before
     * anything is read from the body.
     */
    public function callback(Request $request, RecordPayment $recorder, RazorpayGateway $gateway): JsonResponse
    {
        $callback = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        // Ask the gateway what it actually holds rather than trusting the post
        // body for anything that matters.
        $details = $gateway->fetchPayment($callback['razorpay_payment_id']) ?? [];

        $outcome = $recorder->complete($callback, $details);

        return response()->json([
            'result' => $outcome->result,
            'message' => $outcome->message,
            'data' => $outcome->payment ? new PaymentResource($outcome->payment) : null,
        ], $outcome->succeeded() ? 200 : 422);
    }

    /**
     * Complete a payment without the gateway, for development only.
     *
     * The real flow needs Razorpay to sign the callback, which cannot happen
     * on a machine with no account. This stands in for that - and is refused
     * outright the moment the gateway is switched on, so it can never become a
     * way to mark a real payment paid without paying.
     */
    public function simulate(Payment $payment, RazorpayGateway $gateway, RecordPayment $recorder): JsonResponse
    {
        abort_if($gateway->isLive(), 403, 'The gateway is live. Payments must go through it.');
        abort_if(app()->isProduction(), 403, 'Not available in production.');

        abort_unless(
            $payment->status === PaymentStatus::Initiated,
            422,
            'That payment is not waiting to be completed.'
        );

        /*
         * With no account configured there is no secret, and the signature
         * check below would rightly refuse everything. Rather than skip the
         * check - which would mean testing a path that does not exist in
         * production - a secret is derived from this installation's APP_KEY:
         * stable, unique per install, and never used to talk to anybody.
         */
        if (! config('services.razorpay.secret')) {
            config(['services.razorpay.secret' => hash('sha256', 'simulated-gateway|'.config('app.key'))]);
        }

        $gatewayPaymentId = 'pay_sim_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(14));
        $orderId = (string) $payment->gateway_order_id;

        /*
         * Signed with the configured secret, so it travels the same path as a
         * real callback - signature check, idempotency, the lot. Simulating the
         * payment must not also simulate the verification, or the code being
         * exercised here is not the code that runs in production.
         */
        $outcome = $recorder->complete([
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $gatewayPaymentId,
            'razorpay_signature' => \App\Domain\Billing\RazorpaySignature::sign(
                $orderId,
                $gatewayPaymentId,
                (string) config('services.razorpay.secret'),
            ),
        ], ['method' => 'simulated', 'reference' => null]);

        return response()->json([
            'result' => $outcome->result,
            'message' => $outcome->message,
            'data' => $outcome->payment ? new PaymentResource($outcome->payment) : null,
        ], $outcome->succeeded() ? 200 : 422);
    }

    /**
     * Record a payment taken outside the gateway - cash, or a transfer the
     * customer made directly.
     *
     * Always stamped with who recorded it and when, because a payment nobody
     * can be asked about is how a cash business loses money.
     */
    public function recordManually(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'string', 'exists:subscriptions,id'],
            'amount_paise' => ['required', 'integer', 'min:100'],
            'method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:191'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            /*
             * Whether this payment moves the plan on. Almost always yes; the
             * exception is recording a payment that was already applied, where
             * extending again would give the customer a free period.
             */
            'extend' => ['sometimes', 'boolean'],
        ]);

        $subscription = Subscription::findOrFail($data['subscription_id']);

        $payment = Payment::create([
            'branch_id' => $subscription->branch_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'status' => PaymentStatus::Captured,
            'amount_paise' => $data['amount_paise'],
            'gateway' => 'manual',
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_at' => Carbon::parse($data['paid_at']),
            'notes' => $data['notes'] ?? null,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        $payment->forceFill([
            'invoice_number' => \App\Domain\Billing\InvoiceNumber::next(),
        ])->save();

        /*
         * Move the plan on, exactly as a gateway payment does.
         *
         * Without this a customer who pays in cash has their money banked and
         * their plan left pending - so the round never picks them up and the
         * nightly job chases them for money they have already handed over. The
         * office would have to remember to edit the dates by hand, which is
         * precisely the sort of thing nobody remembers on a busy morning.
         */
        if ($data['extend'] ?? true) {
            app(RecordPayment::class)->extendAfterReconciliation($payment->fresh());
        }

        return response()->json([
            'data' => new PaymentResource($payment->fresh()),
            'subscription' => [
                'status' => $subscription->fresh()->status->value,
                'period_end' => $subscription->fresh()->period_end?->toDateString(),
            ],
        ], 201);
    }

    /**
     * Money taken, grouped for the period asked for.
     */
    public function summary(Request $request): JsonResponse
    {
        // The branch's takings. A customer has no business here at all, so this
        // asks for the report ability rather than view.payment.
        $this->authorize('view.report');

        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : Carbon::today()->startOfMonth();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::today()->endOfDay();

        $captured = Payment::query()->revenue()->between($from, $to);

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'captured_paise' => (int) (clone $captured)->sum('amount_paise'),
                'captured_count' => (clone $captured)->count(),
                // Attempts that never completed. A rising number here means the
                // payment page is broken, so it is reported next to revenue
                // rather than hidden in a log.
                'abandoned_count' => Payment::query()
                    ->where('status', PaymentStatus::Initiated)
                    ->whereBetween('created_at', [$from, $to])
                    ->count(),
                'sector_ids' => SectorContext::currentSectorIds(),
            ],
        ]);
    }
}
