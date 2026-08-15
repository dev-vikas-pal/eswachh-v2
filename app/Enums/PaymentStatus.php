<?php

namespace App\Enums;

/**
 * Where a payment stands.
 *
 * Captured is the only status that counts as revenue. Everything that reports
 * money filters on it, so renaming the stored value would silently zero every
 * figure - which is exactly what would have happened had v1's 'captured' been
 * tidied up during its rebuild.
 */
enum PaymentStatus: string
{
    /** Sent to the gateway; the customer may never come back. */
    case Initiated = 'initiated';

    /** Money taken. This, and only this, is revenue. */
    case Captured = 'captured';

    case Failed = 'failed';

    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Captured => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }

    public function countsAsRevenue(): bool
    {
        return $this === self::Captured;
    }

    /** Statuses an administrator may set by hand after checking the bank. */
    public static function manuallySettable(): array
    {
        return [self::Captured, self::Failed, self::Refunded];
    }
}
