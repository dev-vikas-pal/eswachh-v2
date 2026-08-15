<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Leave = 'leave';
    case Holiday = 'holiday';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Leave => 'On leave',
            self::Holiday => 'Holiday',
        };
    }

    /**
     * Was anybody expected to clean cars?
     *
     * Absent means cars went unserviced and somebody should know. Leave and
     * holiday were planned, so they are not a failure - which is why they are
     * separate cases and not one "not present".
     */
    public function wasWorking(): bool
    {
        return $this === self::Present;
    }

    public function countsAgainstCoverage(): bool
    {
        return $this === self::Absent;
    }
}
