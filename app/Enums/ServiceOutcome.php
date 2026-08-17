<?php

namespace App\Enums;

/**
 * What happened when the cleaner reached the car.
 *
 * v1 recorded only a count of cars serviced, so a car missed because the owner
 * had driven it to work was indistinguishable from a car the cleaner skipped.
 * Those are opposite problems and they are told apart here.
 */
enum ServiceOutcome: string
{
    case Cleaned = 'cleaned';

    /** The car was not there. Nobody's fault. */
    case CarAbsent = 'car_absent';

    /** Gate locked, basement shut, no way to reach it. */
    case AccessDenied = 'access_denied';

    /** The customer waved them off. */
    case CustomerDeclined = 'customer_declined';

    /** Nobody came. This is the one that matters. */
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Cleaned => 'Cleaned',
            self::CarAbsent => 'Car not there',
            self::AccessDenied => 'Could not reach it',
            self::CustomerDeclined => 'Customer declined',
            self::Missed => 'Missed',
        };
    }

    public function wasCleaned(): bool
    {
        return $this === self::Cleaned;
    }

    /**
     * What to tell the customer happened, in their words rather than ours.
     *
     * "Access denied" is a category on a form; "we could not reach the car" is
     * what somebody wants read out to them. Null where there is nothing worth
     * saying - a cleaned car gets its own message, and a missed one is our
     * failure to explain by hand, not by template.
     */
    public function customerExplanation(): ?string
    {
        return match ($this) {
            self::CarAbsent => 'the car was not in its place',
            self::AccessDenied => 'our cleaner could not reach the car',
            self::CustomerDeclined => 'you asked us to skip today',
            default => null,
        };
    }

    /** Did we fail the customer, as opposed to being unable to help? */
    public function isOurFault(): bool
    {
        return in_array($this, [self::Missed, self::AccessDenied], true);
    }
}
