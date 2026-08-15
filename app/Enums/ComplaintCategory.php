<?php

namespace App\Enums;

/**
 * What the complaint is about.
 *
 * A fixed list rather than free text, because the only useful question about
 * complaints is "which of these keeps happening?" - and that cannot be answered
 * over a text column.
 */
enum ComplaintCategory: string
{
    case NotCleaned = 'not_cleaned';
    case PoorQuality = 'poor_quality';
    case CleanerConduct = 'cleaner_conduct';
    case Timing = 'timing';
    case Billing = 'billing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotCleaned => 'Car not cleaned',
            self::PoorQuality => 'Cleaning quality',
            self::CleanerConduct => 'Cleaner conduct',
            self::Timing => 'Timing',
            self::Billing => 'Billing',
            self::Other => 'Something else',
        };
    }

    /**
     * How long the branch has to deal with it, in hours.
     *
     * A car that was not cleaned today can still be cleaned today, so it gets
     * the tightest clock. Conduct goes to a person rather than a round, and
     * billing usually needs a statement checked.
     */
    public function responseHours(): int
    {
        return match ($this) {
            self::NotCleaned => 8,
            self::PoorQuality => 24,
            self::CleanerConduct => 24,
            self::Timing => 48,
            self::Billing => 48,
            self::Other => 48,
        };
    }
}
