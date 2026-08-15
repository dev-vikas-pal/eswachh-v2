<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ComplaintStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\ServiceOutcome;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Payment;
use App\Models\ServiceLog;
use App\Models\Subscription;
use App\Support\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reports.
 *
 * v1 had exactly one - a cloth report - so most of what an owner needed was
 * worked out by hand from spreadsheets. These answer the questions the business
 * actually asks at month end.
 *
 * Every one is branch scoped by the models themselves, so a franchise owner
 * sees their own figures and a super admin sees whichever branch is selected.
 * There is no branch parameter to widen it with.
 */
class ReportController extends Controller
{
    /** What reports exist, so the screen builds its own menu. */
    public function index(): JsonResponse
    {
        $this->authorize('view.report');

        return response()->json([
            'data' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'description' => 'Money taken, by month and by what it was for.'],
                ['key' => 'renewals', 'label' => 'Renewals due', 'description' => 'What is expiring, and what has already lapsed.'],
                ['key' => 'service', 'label' => 'Service delivery', 'description' => 'Cars due against cars actually cleaned.'],
                ['key' => 'complaints', 'label' => 'Complaints', 'description' => 'Volume, categories, and whether they were answered in time.'],
                ['key' => 'cloth', 'label' => 'Cloth', 'description' => 'Bundles sold, cloths used, and balances still owed.'],
            ],
        ]);
    }

    /**
     * Money taken, by month.
     *
     * Counts captured payments only, through the same scope every other revenue
     * figure uses - so this report and the payments screen can never disagree.
     */
    public function revenue(Request $request): JsonResponse
    {
        $this->authorize('view.report');
        [$from, $to] = $this->period($request);

        $rows = Payment::query()->revenue()->between($from, $to)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') AS month, purpose, COUNT(*) AS count, SUM(amount_paise) AS paise")
            ->groupBy('month', 'purpose')
            ->orderBy('month')
            ->get();

        $months = [];

        foreach ($rows as $row) {
            $month = (string) $row->month;
            // The model casts `purpose` to an enum, and an enum cannot be an
            // array key - so take its value rather than the case itself.
            $purpose = $row->purpose instanceof PaymentPurpose ? $row->purpose->value : (string) $row->purpose;

            $months[$month] ??= ['month' => $month, 'total_paise' => 0, 'count' => 0, 'by_purpose' => []];
            $months[$month]['total_paise'] += (int) $row->paise;
            $months[$month]['count'] += (int) $row->count;
            $months[$month]['by_purpose'][$purpose] = (int) $row->paise;
        }

        $captured = Payment::query()->revenue()->between($from, $to);

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'months' => array_values($months),
                'total_paise' => (int) (clone $captured)->sum('amount_paise'),
                'payments' => (clone $captured)->count(),
                // Attempts that never completed, reported beside the money so a
                // broken payment page shows up here rather than only in a log.
                'abandoned' => Payment::query()
                    ->where('status', PaymentStatus::Initiated)
                    ->whereBetween('created_at', [$from, $to])
                    ->count(),
                'recorded_by_hand_paise' => (int) (clone $captured)->whereNotNull('verified_by')->sum('amount_paise'),
            ],
        ]);
    }

    /**
     * What is about to lapse, and what already has.
     *
     * The report that decides how much chasing this week needs.
     */
    public function renewals(): JsonResponse
    {
        $this->authorize('view.report');

        $today = Carbon::today();

        $buckets = [
            'overdue_30_plus' => Subscription::query()->active()->whereDate('period_end', '<', $today->copy()->subDays(30)),
            'overdue_8_to_30' => Subscription::query()->active()->whereBetween('period_end', [$today->copy()->subDays(30), $today->copy()->subDays(8)]),
            'overdue_1_to_7' => Subscription::query()->active()->whereBetween('period_end', [$today->copy()->subDays(7), $today->copy()->subDay()]),
            'due_this_week' => Subscription::query()->active()->whereBetween('period_end', [$today, $today->copy()->addDays(7)]),
            'due_next_three_weeks' => Subscription::query()->active()->whereBetween('period_end', [$today->copy()->addDays(8), $today->copy()->addDays(28)]),
        ];

        $data = [];

        foreach ($buckets as $key => $query) {
            $data[$key] = [
                'count' => (clone $query)->count(),
                // What renewing all of them would be worth, so the chasing can
                // be prioritised by money rather than by count alone.
                'value_paise' => (int) (clone $query)->sum('amount_paise'),
            ];
        }

        return response()->json([
            'data' => array_merge($data, [
                'on_hold' => [
                    'count' => Subscription::query()->onHold()->count(),
                    'value_paise' => (int) Subscription::query()->onHold()->sum('amount_paise'),
                ],
                'as_at' => $today->toDateString(),
            ]),
        ]);
    }

    /**
     * Cars due against cars actually cleaned.
     *
     * Counted from service logs, so nothing here is a typed-in number - which
     * is the whole reason v1's daily figures could not be checked.
     */
    public function service(Request $request): JsonResponse
    {
        $this->authorize('view.report');
        [$from, $to] = $this->period($request);

        $byOutcome = ServiceLog::query()
            ->whereBetween('serviced_on', [$from, $to])
            ->selectRaw('outcome, COUNT(*) AS count')
            ->groupBy('outcome')
            ->pluck('count', 'outcome');

        $cleaned = (int) ($byOutcome[ServiceOutcome::Cleaned->value] ?? 0);
        $logged = (int) $byOutcome->sum();

        $ourFault = collect(ServiceOutcome::cases())
            ->filter(fn (ServiceOutcome $o) => $o->isOurFault())
            ->sum(fn (ServiceOutcome $o) => (int) ($byOutcome[$o->value] ?? 0));

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'logged' => $logged,
                'cleaned' => $cleaned,
                // Kept apart: a car the owner had driven to work is not a
                // service failure, and reporting them together hides the ones
                // that are.
                'our_failures' => $ourFault,
                'not_our_fault' => $logged - $cleaned - $ourFault,
                'by_outcome' => collect(ServiceOutcome::cases())->map(fn (ServiceOutcome $o) => [
                    'outcome' => $o->value,
                    'label' => $o->label(),
                    'count' => (int) ($byOutcome[$o->value] ?? 0),
                ]),
                'busiest_cleaners' => ServiceLog::query()
                    ->whereBetween('serviced_on', [$from, $to])
                    ->where('outcome', ServiceOutcome::Cleaned)
                    ->select('cleaner_id', DB::raw('COUNT(*) AS cleaned'))
                    ->groupBy('cleaner_id')
                    ->orderByDesc('cleaned')
                    ->limit(10)
                    ->with('cleaner:id,name')
                    ->get()
                    ->map(fn (ServiceLog $row) => [
                        'cleaner' => $row->cleaner?->name ?? 'Unknown',
                        'cleaned' => (int) $row->cleaned,
                    ]),
            ],
        ]);
    }

    /**
     * Complaints: what people complain about, and whether we answered in time.
     */
    public function complaints(Request $request): JsonResponse
    {
        $this->authorize('view.report');
        [$from, $to] = $this->period($request);

        $raised = Complaint::query()->whereBetween('created_at', [$from, $to]);

        $resolved = (clone $raised)->whereNotNull('resolved_at')->get();

        // Answered inside the time we promised when it was raised.
        $inTime = $resolved->filter(fn (Complaint $c) => $c->due_at && $c->resolved_at <= $c->due_at)->count();

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'raised' => (clone $raised)->count(),
                'resolved' => $resolved->count(),
                'answered_in_time' => $inTime,
                'answered_late' => $resolved->count() - $inTime,
                // The number that should drive somebody's morning: live, and
                // already past the time we promised.
                'open_now' => Complaint::query()->live()->count(),
                'overdue_now' => Complaint::query()->overdue()->count(),
                'reopened' => (clone $raised)->where('reopened_count', '>', 0)->count(),
                'by_category' => (clone $raised)
                    ->selectRaw('category, COUNT(*) AS count')
                    ->groupBy('category')
                    ->orderByDesc('count')
                    ->get()
                    ->map(fn ($row) => [
                        'category' => $row->category?->value,
                        'label' => $row->category?->label(),
                        'count' => (int) $row->count,
                    ]),
                'by_status' => collect(ComplaintStatus::cases())->map(fn (ComplaintStatus $s) => [
                    'status' => $s->value,
                    'label' => $s->label(),
                    'count' => (clone $raised)->where('status', $s)->count(),
                ]),
            ],
        ]);
    }

    /**
     * Cloth: what was sold, what was used, and what is still owed.
     *
     * v1's only report, rebuilt on the ledger - so the figures can be traced
     * back to individual entries instead of resting on a mutable counter.
     */
    public function cloth(Request $request): JsonResponse
    {
        $this->authorize('view.report');
        [$from, $to] = $this->period($request);

        $entries = DB::table('cloth_entries')
            ->whereBetween('created_at', [$from, $to]);

        if (BranchContext::isRestricted() && $branch = BranchContext::currentBranchId()) {
            $entries->where('branch_id', $branch);
        } elseif (BranchContext::isRestricted()) {
            // Restricted with no branch sees nothing, matching the model scope.
            $entries->whereRaw('1 = 0');
        }

        $byType = (clone $entries)
            ->selectRaw('type, COUNT(*) AS entries, SUM(quantity) AS quantity')
            ->groupBy('type')
            ->get();

        $outstanding = Subscription::query()
            ->where('cloth_service', true)
            ->where('cloth_balance', '>', 0);

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'movements' => $byType->map(fn ($row) => [
                    'type' => $row->type,
                    'entries' => (int) $row->entries,
                    'quantity' => (int) $row->quantity,
                ]),
                'purchased' => (int) ($byType->firstWhere('type', 'purchase')->quantity ?? 0),
                'used' => abs((int) ($byType->firstWhere('type', 'issue')->quantity ?? 0)),
                'written_off' => abs((int) ($byType->firstWhere('type', 'expiry')->quantity ?? 0)),
                'adjusted' => (int) ($byType->firstWhere('type', 'adjustment')->quantity ?? 0),
                // Cloths customers have paid for and not yet had. A liability,
                // not a stock figure.
                'outstanding' => [
                    'subscriptions' => (clone $outstanding)->count(),
                    'cloths' => (int) (clone $outstanding)->sum('cloth_balance'),
                ],
                'running_low' => (clone $outstanding)->where('cloth_balance', '<=', 10)->count(),
            ],
        ]);
    }

    /**
     * The period a report covers.
     *
     * Defaults to the current financial year to date, because that is the
     * window the questions are actually asked in.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::today()->endOfDay();

        if (isset($filters['from'])) {
            return [Carbon::parse($filters['from'])->startOfDay(), $to];
        }

        // The Indian financial year to date, because that is the window these
        // questions are actually asked in.
        $today = Carbon::today();
        $yearStart = $today->month >= 4 ? $today->year : $today->year - 1;

        return [Carbon::create($yearStart, 4, 1)->startOfDay(), $to];
    }
}
