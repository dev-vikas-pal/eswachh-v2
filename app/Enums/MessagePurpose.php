<?php

namespace App\Enums;

/**
 * Why we are messaging somebody.
 *
 * The purpose is half of the key that stops a customer being messaged twice
 * about the same thing on the same day, so these values are load bearing:
 * renaming one silently allows a duplicate.
 */
enum MessagePurpose: string
{
    case RenewalDue = 'renewal_due';
    case RenewalOverdue = 'renewal_overdue';
    case PutOnHold = 'put_on_hold';
    case PaymentReceipt = 'payment_receipt';
    case ClothsLow = 'cloths_low';

    /** Anything the office sent by hand from a template of its own. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::RenewalDue => 'Renewal due',
            self::RenewalOverdue => 'Renewal overdue',
            self::PutOnHold => 'Put on hold',
            self::PaymentReceipt => 'Payment receipt',
            self::ClothsLow => 'Cloths running low',
            self::Custom => 'Sent by hand',
        };
    }

    /** The provider template this maps to. */
    public function template(): string
    {
        return match ($this) {
            self::RenewalDue => 'eswachh_renewal_due',
            self::RenewalOverdue => 'eswachh_renewal_overdue',
            self::PutOnHold => 'eswachh_on_hold',
            self::PaymentReceipt => 'eswachh_receipt',
            self::ClothsLow => 'eswachh_cloths_low',
            self::Custom => 'eswachh_custom',
        };
    }
}
