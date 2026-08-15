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

    /** Did we fail the customer, as opposed to being unable to help? */
    public function isOurFault(): bool
    {
        return in_array($this, [self::Missed, self::AccessDenied], true);
    }
}
