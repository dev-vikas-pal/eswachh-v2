<?php

namespace App\Enums;

enum ComplaintPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
        };
    }

    /**
     * Multiplier on the category's response time.
     *
     * High halves the clock rather than setting a fixed short one, so the
     * relative urgency of the categories survives.
     */
    public function clockFactor(): float
    {
        return match ($this) {
            self::High => 0.5,
            self::Normal => 1.0,
            self::Low => 2.0,
        };
    }
}
