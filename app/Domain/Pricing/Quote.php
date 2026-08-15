<?php

namespace App\Domain\Pricing;

/**
 * A price, and every master that went into it.
 *
 * The itemisation is the point. A subscription price is assembled from five
 * separate master records, so "why is this ₹850?" is a real question that gets
 * asked - by customers, by franchise owners querying a renewal, and by whoever
 * has to explain a figure that changed because somebody edited a master.
 * Returning only a total makes that unanswerable.
 */
class Quote
{
    /**
     * @param  array<int, QuoteLine>  $lines
     * @param  array<int, string>  $warnings  Masters that were asked for and not found
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $subtotalPaise,
        public readonly int $discountPaise,
        public readonly int $clothPaise,
        public readonly int $totalPaise,
        public readonly int $months,
        public readonly array $warnings = [],
    ) {}

    /**
     * Was anything the price depends on missing?
     *
     * A quote with warnings is under-priced by whatever the missing master used
     * to charge, so it must not be shown to a customer as a final figure
     * without somebody looking at it.
     */
    public function isComplete(): bool
    {
        return $this->warnings === [];
    }

    public function total(): float
    {
        return $this->totalPaise / 100;
    }

    /** What one month of this works out at, for comparing plans. */
    public function perMonthPaise(): int
    {
        return $this->months > 0 ? intdiv($this->totalPaise, $this->months) : $this->totalPaise;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'months' => $this->months,
            'lines' => array_map(fn (QuoteLine $line) => $line->toArray(), $this->lines),
            'subtotal_paise' => $this->subtotalPaise,
            'discount_paise' => $this->discountPaise,
            'cloth_paise' => $this->clothPaise,
            'total_paise' => $this->totalPaise,
            'total' => $this->total(),
            'per_month_paise' => $this->perMonthPaise(),
            'formatted' => '₹'.number_format($this->total(), 2),
            'complete' => $this->isComplete(),
            'warnings' => $this->warnings,
        ];
    }
}
