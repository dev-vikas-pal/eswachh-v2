<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Support\Http\SortsLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What has been said to customers, and what is about to be.
 *
 * v1 had this as a screen and it earns one: "did we tell them?" is asked on
 * every chasing call, and a log file is not an answer somebody on the phone can
 * use. It also makes a failing integration visible - a column of "not
 * delivered" is far harder to ignore than a warning in a log.
 */
class ReminderController extends Controller
{
    use SortsLists;

    private const SORTABLE = [
        /*
         * sent_on is a date, so a day's worth of messages all tie on it and the
         * order within the day is whatever the database felt like - which reads
         * as "sorting does nothing" when most of the list was sent today.
         * created_at breaks the tie and puts them in the order they went out.
         */
        'sent' => 'sent_on,created_at',
        'status' => 'status',
        'purpose' => 'purpose',
        'recipient' => 'recipient',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('view.subscription');

        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'purpose' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Message::query()
            ->with('customer:id,name', 'subscription:id,vehicle_id', 'subscription.vehicle:id,registration')
            ->latest('created_at');

        // Branch scoping: Message is not a BaseModel, so it is applied here.
        if (\App\Support\Tenancy\BranchContext::isRestricted()) {
            $branchId = \App\Support\Tenancy\BranchContext::currentBranchId();

            // Same rule as the global scope: restricted with no branch sees
            // nothing, never everything.
            $branchId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('branch_id', $branchId);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($purpose = $filters['purpose'] ?? null) {
            $query->where('purpose', $purpose);
        }

        if ($from = $filters['from'] ?? null) {
            $query->whereDate('sent_on', '>=', Carbon::parse($from));
        }

        if ($to = $filters['to'] ?? null) {
            $query->whereDate('sent_on', '<=', Carbon::parse($to));
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('recipient', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        }

        $this->applySort($query, $request, self::SORTABLE, 'sent');

        $counts = [
            'sent' => (clone $query)->where('status', MessageStatus::Sent)->count(),
            'failed' => (clone $query)->where('status', MessageStatus::Failed)->count(),
            // Recorded but deliberately not delivered - development, or the
            // integration switched off. Shown so it is never mistaken for sent.
            'suppressed' => (clone $query)->where('status', MessageStatus::Suppressed)->count(),
        ];

        $messages = $query->paginate($filters['per_page'] ?? 30);

        return response()->json([
            'data' => array_map(fn (Message $m) => [
                'id' => $m->id,
                'customer' => $m->customer?->name,
                'car' => $m->subscription?->vehicle?->registration,
                'recipient' => $m->recipient,
                'purpose' => $m->purpose?->value,
                'purpose_label' => $m->purpose?->label(),
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
                'reached_customer' => $m->status->reachedTheCustomer(),
                'suppressed_reason' => $m->suppressed_reason,
                'error' => $m->error,
                'body' => $m->body,
                'sent_on' => $m->sent_on?->toDateString(),
                'sent_at' => $m->sent_at?->toIso8601String(),
                /*
                 * When this actually happened, to the minute. sent_at is only
                 * set once a provider accepted it, so a suppressed or failed
                 * message has none - and "no time at all" on two thirds of the
                 * list is worse than showing when we recorded it.
                 */
                'at' => ($m->sent_at ?? $m->created_at)?->toIso8601String(),
            ], $messages->items()),
            'meta' => [
                'total' => $messages->total(),
                'per_page' => $messages->perPage(),
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
            ] + $counts + $this->sortMeta($request, self::SORTABLE, 'sent'),
        ]);
    }
}
