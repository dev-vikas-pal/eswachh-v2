<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Things that need somebody's attention.
 *
 * Read state is per person; resolved state is shared. Marking an alert read for
 * yourself must not clear it for the person who actually has to act on it, and
 * resolving it must clear it for everybody - those are different actions and
 * they are separate endpoints.
 */
class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $alerts = SectorContext::withoutScope(fn () => Alert::query()
            ->visibleTo($user)
            ->open()
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->latest('created_at')
            ->limit(50)
            ->get());

        $readIds = $user->alertReads()->pluck('alert_id')->all();

        return response()->json([
            'data' => $alerts->map(fn (Alert $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'severity' => $a->severity,
                'title' => $a->title,
                'body' => $a->body,
                'link' => $a->link_route ? ['route' => $a->link_route, 'params' => $a->link_params] : null,
                'read' => in_array($a->id, $readIds, true),
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
            'meta' => [
                // What the bell shows: unread, not merely open.
                'unread' => $alerts->reject(fn (Alert $a) => in_array($a->id, $readIds, true))->count(),
                'critical' => $alerts->where('severity', 'critical')->count(),
            ],
        ]);
    }

    /** Seen it. Only for me. */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->alertReads()->syncWithoutDetaching([$id => ['read_at' => now()]]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $ids = SectorContext::withoutScope(fn () => Alert::query()
            ->visibleTo($request->user())->open()->pluck('id'));

        $request->user()->alertReads()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['read_at' => now()]])->all()
        );

        return response()->json(['message' => 'All marked as read.']);
    }

    /** Dealt with. For everybody. */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $alert = SectorContext::withoutScope(fn () => Alert::query()
            ->visibleTo($request->user())->findOrFail($id));

        $alert->forceFill(['resolved_at' => now(), 'resolved_by' => $request->user()->id])->save();

        return response()->json(['message' => 'Closed.']);
    }
}
