<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Deliberately not delivered.
     *
     * Everything outside production lands here: the row records exactly what
     * would have been sent, and no customer's phone rings. v1 had no such
     * state, which is why its test suite messaged real people.
     */
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Suppressed => 'Not sent (delivery off)',
        };
    }

    public function reachedTheCustomer(): bool
    {
        return $this === self::Sent;
    }
}
