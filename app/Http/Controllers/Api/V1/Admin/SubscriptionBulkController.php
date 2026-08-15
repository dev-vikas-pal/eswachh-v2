<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Messaging\Messenger;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Doing one thing to many plans at once.
 *
 * This is how the office actually works a morning: filter the list, tick the
 * rows, reassign or message the lot. v1 had it and it is the single biggest
 * thing v2 was missing.
 *
 * Every action here re-reads the ids through the branch scope rather than
 * trusting the list the browser sent. A page of ticked boxes is client input,
 * and a doctored one must not reach another franchise's customers.
 */
class SubscriptionBulkController extends Controller
{
    /** Above this, somebody is doing something they should think about first. */
    private const MAX_PER_ACTION = 200;

    /**
     * Put many cars on one cleaner.
     */
    public function assignCleaner(Request $request): JsonResponse
    {
        $this->authorize('assign.cleaner');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_ACTION],
            'ids.*' => ['string'],
            'cleaner_id' => ['present', 'nullable', 'string', 'exists:users,id'],
        ]);

        $cleaner = null;

        if ($data['cleaner_id']) {
            // Scoped: a cleaner from another branch would never see the work.
            $cleaner = User::query()->visible()->findOrFail($data['cleaner_id']);

            if ($cleaner->role !== UserRole::Cleaner) {
                return response()->json(['message' => 'That person is not a cleaner.'], 422);
            }
        }

        // Re-read through the scope. Ids the browser sent that this user cannot
        // see simply are not here, and are reported as skipped.
        $subscriptions = Subscription::query()
            ->whereIn('id', $data['ids'])
            ->with('vehicle')
            ->get();

        $assigned = 0;
        $skipped = [];

        foreach ($subscriptions as $subscription) {
            $vehicle = $subscription->vehicle;

            if (! $vehicle) {
                $skipped[] = 'A plan with no vehicle was skipped.';

                continue;
            }

            if ($cleaner && $cleaner->branch_id !== $vehicle->branch_id) {
                $skipped[] = "{$vehicle->registration}: that cleaner works in a different branch.";

                continue;
            }

            $vehicle->forceFill(['assigned_cleaner_id' => $cleaner?->id])->save();
            $assigned++;
        }

        $unreachable = count($data['ids']) - $subscriptions->count();

        return response()->json([
            'message' => $cleaner
                ? "{$assigned} car(s) assigned to {$cleaner->name}."
                : "{$assigned} car(s) left unassigned.",
            'assigned' => $assigned,
            // Said out loud rather than silently dropped: a bulk action that
            // quietly does less than asked is how work goes missing.
            'skipped' => $skipped,
            'not_visible' => $unreachable,
        ]);
    }

    /**
     * Message many customers at once, from a template.
     */
    public function sendMessage(Request $request, Messenger $messenger): JsonResponse
    {
        $this->authorize('update.subscription');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_ACTION],
            'ids.*' => ['string'],
            'template_key' => ['required', 'string', 'exists:message_templates,key'],
        ]);

        $template = MessageTemplate::query()->where('key', $data['template_key'])->firstOrFail();

        if (! $template->bulk_sendable) {
            // A receipt for money nobody just paid is worse than no receipt.
            return response()->json([
                'message' => 'That template is not one that can be sent in bulk.',
            ], 422);
        }

        $subscriptions = Subscription::query()
            ->whereIn('id', $data['ids'])
            ->with('customer', 'vehicle')
            ->get();

        $sent = 0;
        $alreadyToday = 0;
        $noPhone = 0;

        foreach ($subscriptions as $subscription) {
            if (! $subscription->customer?->phone) {
                $noPhone++;

                continue;
            }

            // The same once-a-day rule as the nightly job, through the same
            // code - so a bulk send cannot double up on what was automatic.
            $message = $messenger->sendTemplate($subscription, $template);

            $message ? $sent++ : $alreadyToday++;
        }

        Log::info('Bulk message sent.', [
            'template' => $template->key,
            'requested' => count($data['ids']),
            'sent' => $sent,
            'by' => $request->user()->id,
        ]);

        $parts = ["{$sent} message(s) recorded"];

        if (! $messenger->deliveryEnabled()) {
            $parts[0] = "{$sent} message(s) recorded but not delivered: ".$messenger->suppressionReason();
        }

        if ($alreadyToday) {
            $parts[] = "{$alreadyToday} already had that message today";
        }

        if ($noPhone) {
            $parts[] = "{$noPhone} had no phone number";
        }

        return response()->json([
            'message' => implode('. ', $parts).'.',
            'sent' => $sent,
            'skipped_already_sent' => $alreadyToday,
            'skipped_no_phone' => $noPhone,
            'delivered' => $messenger->deliveryEnabled(),
        ]);
    }

    /**
     * Templates the office may pick from.
     */
    public function templates(): JsonResponse
    {
        $this->authorize('update.subscription');

        return response()->json([
            'data' => MessageTemplate::query()->bulkSendable()->get()->map(fn (MessageTemplate $t) => [
                'key' => $t->key,
                'name' => $t->name,
                'description' => $t->description,
                // Shown in the picker so nobody sends wording they have not read.
                'preview' => $t->body,
            ]),
        ]);
    }
}
