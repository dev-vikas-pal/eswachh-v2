<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Messaging\Messenger;
use App\Enums\MessagePurpose;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The things the office does to a subscription.
 *
 * Each is its own endpoint rather than a general update, so the server decides
 * what is allowed and each action leaves a record of who did it.
 */
class SubscriptionActionController extends Controller
{
    /**
     * Send the renewal reminder now, by hand.
     *
     * v1 had this and it matters: the nightly job covers the usual case, but
     * somebody on the phone to a customer wants to send the message while they
     * are still talking to them.
     *
     * The same once-a-day rule applies as the automatic one, through the same
     * code - otherwise a customer gets the nightly message and then a second
     * one from whoever is chasing them.
     */
    public function sendReminder(Request $request, Subscription $subscription, Messenger $messenger): JsonResponse
    {
        $this->authorize('update.subscription');

        $data = $request->validate([
            'purpose' => ['sometimes', Rule::in([
                MessagePurpose::RenewalDue->value,
                MessagePurpose::RenewalOverdue->value,
            ])],
            // Optional: a person can say it better than a template when they
            // have just spoken to the customer.
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $subscription->load('customer', 'vehicle');

        if (! $subscription->customer?->phone) {
            throw ValidationException::withMessages([
                'customer' => 'This customer has no phone number on record.',
            ]);
        }

        $purpose = isset($data['purpose'])
            ? MessagePurpose::from($data['purpose'])
            // Chosen from the facts rather than asked for: overdue is overdue.
            : ($subscription->isExpired() ? MessagePurpose::RenewalOverdue : MessagePurpose::RenewalDue);

        $body = $data['message'] ?? $this->defaultBody($subscription, $purpose);

        $message = $messenger->send($subscription, $purpose, $body);

        if (! $message) {
            // Null means one has already gone today. Said plainly rather than
            // reported as a success, so nobody sits waiting for a message that
            // is not coming.
            return response()->json([
                'message' => 'This customer has already been sent that message today.',
                'sent' => false,
            ], 409);
        }

        return response()->json([
            'message' => $message->status->reachedTheCustomer()
                ? 'Message sent.'
                : 'Recorded, but not delivered: '.($message->suppressed_reason ?? 'delivery is off in this environment.'),
            'sent' => $message->status->reachedTheCustomer(),
            'body' => $body,
        ]);
    }

    /**
     * Put a cleaner on this car.
     *
     * Assigned to the vehicle rather than the subscription, because a cleaner
     * services a car on a round - renewing the plan does not change who cleans
     * it, and it should not have to be set again every period.
     */
    public function assignCleaner(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('assign.cleaner');

        $data = $request->validate([
            'cleaner_id' => ['present', 'nullable', 'string', 'exists:users,id'],
        ]);

        $vehicle = $subscription->vehicle;

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'cleaner_id' => 'This plan has no vehicle to assign anybody to.',
            ]);
        }

        $cleaner = null;

        if ($data['cleaner_id']) {
            // Scoped, so a franchise owner cannot put another branch's cleaner
            // on their round - that person would never see the work.
            $cleaner = User::query()->visible()->findOrFail($data['cleaner_id']);

            if ($cleaner->role !== UserRole::Cleaner) {
                throw ValidationException::withMessages([
                    'cleaner_id' => 'That person is not a cleaner.',
                ]);
            }

            if ($cleaner->branch_id !== $vehicle->branch_id) {
                throw ValidationException::withMessages([
                    'cleaner_id' => 'That cleaner works in a different branch.',
                ]);
            }
        }

        $vehicle->forceFill(['assigned_cleaner_id' => $cleaner?->id])->save();

        return response()->json([
            'message' => $cleaner
                ? "{$cleaner->name} will clean {$vehicle->registration}."
                : 'Cleaner removed. This car is now unassigned.',
            'cleaner' => $cleaner ? ['id' => $cleaner->id, 'name' => $cleaner->name] : null,
        ]);
    }

    /**
     * Cleaners this plan could be given to.
     */
    public function availableCleaners(Subscription $subscription): JsonResponse
    {
        $this->authorize('assign.cleaner');

        $cleaners = User::query()
            ->visible()
            ->where('role', UserRole::Cleaner)
            ->where('status', true)
            ->when($subscription->branch_id, fn ($q, $b) => $q->where('branch_id', $b))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $cleaners->map(fn (User $c) => ['id' => $c->id, 'name' => $c->name]),
            'current' => $subscription->vehicle?->assigned_cleaner_id,
        ]);
    }

    /**
     * Pause or restart a plan by hand.
     */
    public function setStatus(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('update.subscription');

        $data = $request->validate([
            'status' => ['required', Rule::in([
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Hold->value,
            ])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = SubscriptionStatus::from($data['status']);

        $subscription->forceFill([
            'status' => $status,
            'held_at' => $status === SubscriptionStatus::Hold ? now() : null,
        ])->save();

        return response()->json([
            'message' => $status === SubscriptionStatus::Hold
                ? 'Plan paused. The car will drop off the round tomorrow.'
                : 'Plan restarted.',
        ]);
    }

    private function defaultBody(Subscription $subscription, MessagePurpose $purpose): string
    {
        $car = $subscription->vehicle?->registration ?? 'your car';
        $name = $subscription->customer?->name ?? 'there';
        $amount = number_format($subscription->amount(), 0);

        return $purpose === MessagePurpose::RenewalOverdue
            ? "Hello {$name}, the cleaning plan for {$car} is now overdue. "
                ."Please renew for Rs {$amount} - the service will be paused if we do not hear from you."
            : "Hello {$name}, the cleaning plan for {$car} is due for renewal. "
                ."Renew for Rs {$amount} to keep the service running.";
    }
}
