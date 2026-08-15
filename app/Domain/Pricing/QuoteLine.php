<?php

namespace App\Domain\Pricing;

/**
 * One master's contribution to a price.
 *
 * Carries the master's id as well as its name, so a quote shown to a customer
 * can be traced back to the exact records it was built from - including after
 * somebody edits one of them.
 */
class QuoteLine
{
    public function __construct(
        /** category | package | service_type | society | duration | cloth */
        public readonly string $source,
        public readonly string $label,
        public readonly int $amountPaise,
        public readonly ?string $sourceId = null,
        /** Multiplied by the months, as opposed to charged once. */
        public readonly bool $recurring = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'label' => $this->label,
            'amount_paise' => $this->amountPaise,
            'amount' => $this->amountPaise / 100,
            'recurring' => $this->recurring,
        ];
    }
}
