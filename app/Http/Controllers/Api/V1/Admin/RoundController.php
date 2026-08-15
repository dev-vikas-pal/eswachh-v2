<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Operations\DailyRound;
use App\Enums\AttendanceStatus;
use App\Enums\ServiceOutcome;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceLogResource;
use App\Models\Attendance;
use App\Models\ClothMovement;
use App\Models\ServiceLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The cleaner's day, and the office's view of it.
 */
class RoundController extends Controller
{
    public function __construct(private DailyRound $round) {}

    /**
     * The cars this cleaner is due to visit, with what has already happened.
     */
    public function today(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date'],
            'cleaner_id' => ['sometimes', 'string', 'exists:users,id'],
        ]);

        $on = isset($filters['date']) ? Carbon::parse($filters['date']) : Carbon::today();
        $cleaner = $this->resolveCleaner($request, $filters);

        $vehicles = $this->round->due($cleaner, $on);

        // One query for the whole round rather than one per car.
        $logs = ServiceLog::query()
            ->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->whereDate('serviced_on', $on)
            ->get()
            ->keyBy('vehicle_id');

        return response()->json([
            'data' => [
                'summary' => $this->round->summary($cleaner, $on),
                'stops' => $vehicles->map(function (Vehicle $vehicle) use ($logs) {
                    $log = $logs->get($vehicle->id);

                    return [
                        'vehicle' => [
                            'id' => $vehicle->id,
                            'registration' => $vehicle->registration,
                        ],
                        'customer' => $vehicle->customer ? [
                            'name' => $vehicle->customer->name,
                            'phone' => $vehicle->customer->phone,
                            'house_no' => $vehicle->customer->house_no,
                            'address' => $vehicle->customer->address,
                            // What the customer asked for, so the round can be
                            // walked in an order that suits them.
                            'preferred_time' => $vehicle->customer->preferred_time,
                        ] : null,
                        'done' => $log !== null,
                        'log' => $log ? new ServiceLogResource($log) : null,
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * Record what happened at one car.
     */
    public function recordService(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('record.service');

        $data = $request->validate([
            'outcome' => ['required', Rule::enum(ServiceOutcome::class)],
            'date' => ['sometimes', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $cleaner = $request->user()->role === UserRole::Cleaner
            ? $request->user()
            // An owner recording on someone's behalf still records whose round
            // it was, not their own name.
            : ($vehicle->cleaner ?? $request->user());

        $log = $this->round->record(
            $vehicle,
            $cleaner,
            ServiceOutcome::from($data['outcome']),
            isset($data['date']) ? Carbon::parse($data['date']) : Carbon::today(),
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => new ServiceLogResource($log->load('vehicle', 'cleaner')),
        ], 201);
    }

    /**
     * Record cloths collected from a car, or returned to it.
     *
     * Written per car per day and corrected in place, so a second tap on a slow
     * phone replaces the count rather than doubling it.
     */
    public function recordCloth(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('record.cloth');

        $data = $request->validate([
            'direction' => ['required', 'string', 'in:pickup,delivery'],
            'cloth_count' => ['required', 'integer', 'min:1', 'max:500'],
            'date' => ['sometimes', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $on = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::today();

        $movement = ClothMovement::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', $data['direction'])
            ->whereDate('moved_on', $on)
            ->first();

        $attributes = [
            'branch_id' => $vehicle->branch_id,
            'vehicle_id' => $vehicle->id,
            'subscription_id' => $vehicle->currentSubscription?->id,
            'cleaner_id' => $request->user()->id,
            'direction' => $data['direction'],
            'cloth_count' => $data['cloth_count'],
            'moved_on' => $on,
            'note' => $data['note'] ?? null,
        ];

        if ($movement) {
            $movement->forceFill($attributes)->save();
        } else {
            $movement = ClothMovement::create($attributes);
        }

        return response()->json([
            'message' => $data['direction'] === ClothMovement::PICKUP
                ? "{$data['cloth_count']} cloth(s) collected from {$vehicle->registration}."
                : "{$data['cloth_count']} cloth(s) returned to {$vehicle->registration}.",
            'data' => [
                'id' => $movement->id,
                'direction' => $movement->direction,
                'cloth_count' => $movement->cloth_count,
            ],
        ], 201);
    }

    /**
     * Cloths out at the laundry, for the office.
     */
    public function clothLedger(Request $request): JsonResponse
    {
        $this->authorize('view.cloth');

        $filters = $request->validate(['date' => ['sometimes', 'date']]);
        $on = isset($filters['date']) ? Carbon::parse($filters['date']) : Carbon::today();

        $movements = ClothMovement::query()->on($on)
            ->with('vehicle:id,registration', 'cleaner:id,name')
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => [
                'date' => $on->toDateString(),
                'picked_up' => (int) $movements->where('direction', ClothMovement::PICKUP)->sum('cloth_count'),
                'delivered' => (int) $movements->where('direction', ClothMovement::DELIVERY)->sum('cloth_count'),
                // Collected and not yet returned, across all time - cloths a
                // customer has paid for and cannot currently use.
                'outstanding' => ClothMovement::outstanding(BranchContext::currentBranchId()),
                'movements' => $movements->map(fn (ClothMovement $m) => [
                    'id' => $m->id,
                    'car' => $m->vehicle?->registration,
                    'cleaner' => $m->cleaner?->name,
                    'direction' => $m->direction,
                    'cloth_count' => $m->cloth_count,
                ]),
            ],
        ]);
    }

    public function markAttendance(Request $request): JsonResponse
    {
        $this->authorize('record.attendance');

        $data = $request->validate([
            'cleaner_id' => ['sometimes', 'string', 'exists:users,id'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'date' => ['sometimes', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * Deliberately not resolveCleaner(). That quietly substitutes the
         * signed in cleaner, which is right for reading a round - a cleaner's
         * round is their own by definition - but wrong for a write: silently
         * marking yourself present when you asked to mark somebody else does
         * something different from what was asked, and says it worked.
         */
        if ($request->user()->role === UserRole::Cleaner) {
            $askedForSomeoneElse = isset($data['cleaner_id'])
                && $data['cleaner_id'] !== $request->user()->id;

            abort_if($askedForSomeoneElse, 403, 'You can only mark your own attendance.');

            $cleaner = $request->user();
        } else {
            abort_unless(isset($data['cleaner_id']), 422, 'A cleaner must be given.');

            $cleaner = User::query()->visible()->findOrFail($data['cleaner_id']);
        }

        $attendance = $this->round->markAttendance(
            $cleaner,
            AttendanceStatus::from($data['status']),
            isset($data['date']) ? Carbon::parse($data['date']) : Carbon::today(),
            $request->user(),
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $attendance->id,
                'cleaner' => ['id' => $cleaner->id, 'name' => $cleaner->name],
                'worked_on' => $attendance->worked_on?->toDateString(),
                'status' => [
                    'value' => $attendance->status->value,
                    'label' => $attendance->status->label(),
                ],
                // Visible on purpose: a week filled in on a Friday is not
                // evidence of anything.
                'marked_late' => $attendance->wasMarkedLate(),
                'note' => $attendance->note,
            ],
        ], 201);
    }

    /**
     * Every cleaner's day at a glance, for the office.
     */
    public function coverage(Request $request): JsonResponse
    {
        $this->authorize('view.attendance');

        $filters = $request->validate(['date' => ['sometimes', 'date']]);
        $on = isset($filters['date']) ? Carbon::parse($filters['date']) : Carbon::today();

        $cleaners = User::query()->visible()->role(UserRole::Cleaner)->where('status', true)->get();

        $attendance = Attendance::query()
            ->whereIn('cleaner_id', $cleaners->pluck('id'))
            ->whereDate('worked_on', $on)
            ->get()
            ->keyBy('cleaner_id');

        $rows = $cleaners->map(function (User $cleaner) use ($attendance, $on) {
            $summary = $this->round->summary($cleaner, $on);
            $marked = $attendance->get($cleaner->id);

            return array_merge($summary, [
                'attendance' => $marked?->status->value,
                'attendance_label' => $marked?->status->label(),
                'marked_late' => (bool) $marked?->wasMarkedLate(),
                // Nobody has said whether this person worked today. On a
                // dashboard that is a question, not a blank.
                'unmarked' => $marked === null,
            ]);
        });

        return response()->json([
            'data' => [
                'date' => $on->toDateString(),
                'cleaners' => $rows->values(),
                'totals' => [
                    'due' => $rows->sum('due'),
                    'cleaned' => $rows->sum('cleaned'),
                    'failed' => $rows->sum('failed'),
                    'unaccounted' => $rows->sum('unaccounted'),
                    'unmarked_cleaners' => $rows->where('unmarked', true)->count(),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveCleaner(Request $request, array $filters): User
    {
        if ($request->user()->role === UserRole::Cleaner) {
            // A cleaner's round is always their own. A cleaner_id in the query
            // string is ignored rather than trusted.
            return $request->user();
        }

        abort_unless(isset($filters['cleaner_id']), 422, 'A cleaner must be given.');

        // Scoped, so an owner cannot look at another franchise's cleaner.
        return User::query()->visible()->findOrFail($filters['cleaner_id']);
    }
}
