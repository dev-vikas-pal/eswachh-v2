<?php

namespace App\Enums;

/**
 * Where a subscription period stands.
 *
 * Note what is absent: there is no Expired case. An expired subscription is an
 * Active period whose period_end has passed, exactly as in v1 - it keeps being
 * serviced until somebody renews it or the weekly job holds it. Storing it as a
 * status would mean two places could disagree about the same fact.
 */
enum SubscriptionStatus: string
{
    /** Created, payment not completed. */
    case Pending = 'pending';

    /** Paid and running. */
    case Active = 'active';

    /** Paused, usually overdue for renewal. */
    case Hold = 'hold';

    /** Finished and not renewed. */
    case Ended = 'ended';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Is the car being cleaned under this period? */
    public function isServiceable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Statuses that count towards "live subscriptions" on the dashboard.
     *
     * @return array<int, self>
     */
    public static function live(): array
    {
        return [self::Active, self::Hold];
    }
}
