<?php

namespace App\Enums;

enum ClothEntryType: string
{
    /** A bundle bought, with or after the subscription. */
    case Purchase = 'purchase';

    /** One cloth used on one clean. */
    case Issue = 'issue';

    /** A correction made by a person, which always needs a reason. */
    case Adjustment = 'adjustment';

    /** Cloths written off when a subscription ended. */
    case Expiry = 'expiry';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Bundle bought',
            self::Issue => 'Cloth used',
            self::Adjustment => 'Adjustment',
            self::Expiry => 'Written off',
        };
    }

    /** Does this type add cloths, or take them away? */
    public function addsStock(): bool
    {
        return $this === self::Purchase;
    }

    /** Must a person say why? Only where the change is not self-explaining. */
    public function needsReason(): bool
    {
        return in_array($this, [self::Adjustment, self::Expiry], true);
    }
}
