<?php

namespace App\Enums;

/**
 * Where a complaint stands.
 *
 * Overdue is deliberately not a case here, for the same reason expired is not a
 * SubscriptionStatus: it is derived from due_at and the clock. A stored
 * "overdue" flag is only correct until the next minute passes.
 */
enum ComplaintStatus: string
{
    /** Raised, nobody owns it yet. */
    case Open = 'open';

    /** Someone owns it. */
    case Assigned = 'assigned';

    /** Dealt with, awaiting the customer's word. */
    case Resolved = 'resolved';

    /** Finished. Only a reopen brings it back. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Assigned => 'Assigned',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** Still someone's problem, and still counting against the clock. */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::Assigned], true);
    }

    /** Statuses a complaint can move to from here. */
    public function allows(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Open => [self::Assigned, self::Resolved, self::Closed],
            self::Assigned => [self::Assigned, self::Resolved, self::Closed],
            // A resolved complaint can be reopened by the customer, or signed
            // off. It cannot jump straight back to open with no owner.
            self::Resolved => [self::Assigned, self::Closed],
            // Closed is final. Reopening creates the move to Assigned only
            // through the reopen path, which records why.
            self::Closed => [self::Assigned],
        }, true);
    }
}
