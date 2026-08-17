<?php

namespace App\Enums;

/**
 * Why we are messaging somebody.
 *
 * The purpose is half of the key that stops a customer being messaged twice
 * about the same thing on the same day, so these values are load bearing:
 * renaming one silently allows a duplicate.
 *
 * The twelve the business actually sends are set out in the requirements
 * document. v1 sent all of them; each case below is one of those, plus the two
 * v1 also had for its own operational reasons (put on hold, sent by hand).
 */
enum MessagePurpose: string
{
    // [1] and [2]: a new plan is paid for.
    case SubscriptionStarted = 'subscription_started';
    case SubscriptionStartedAdmin = 'subscription_started_admin';

    // [3] a plan is renewed, [4] cloths are topped up.
    case Renewed = 'renewed';
    case ClothTopUp = 'cloth_top_up';

    // [5] a complaint is raised - to the cleaners, not the customer.
    case ComplaintRaised = 'complaint_raised';

    // [6] somebody is given the car.
    case CleanerAssigned = 'cleaner_assigned';

    // [8] the car was cleaned today.
    case CleaningDone = 'cleaning_done';

    /*
     * And the other half of [8]: the cleaner came and could not do it.
     *
     * Silence on those days is what a customer reads as "nobody turned up",
     * and it is the complaint the office then has to answer with no record of
     * what happened. Saying it at the time is cheaper than explaining it later.
     */
    case CleaningMissed = 'cleaning_missed';

    /*
     * The day's round, told once.
     *
     * Replaces the per-car messages above for anything the cleaner records.
     * They fired the moment somebody tapped, which on an early round is six in
     * the morning, and a household with two cars was woken twice. This is the
     * same information at an hour a customer is awake to read it.
     */
    case DailySummary = 'daily_summary';

    // [9] and [10]: cloths collected and returned.
    case ClothPickup = 'cloth_pickup';
    case ClothDelivery = 'cloth_delivery';

    // [11] renewal is coming, or has passed.
    case RenewalDue = 'renewal_due';
    case RenewalOverdue = 'renewal_overdue';

    // [12] the cloth balance has run down.
    case ClothsLow = 'cloths_low';

    case PutOnHold = 'put_on_hold';
    case PaymentReceipt = 'payment_receipt';

    /** [7] Anything the office sent by hand from a template of its own. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::SubscriptionStarted => 'New plan started',
            self::SubscriptionStartedAdmin => 'New plan, told the office',
            self::Renewed => 'Plan renewed',
            self::ClothTopUp => 'Cloths topped up',
            self::ComplaintRaised => 'Complaint raised',
            self::CleanerAssigned => 'Cleaner assigned',
            self::CleaningDone => 'Cleaning done',
            self::CleaningMissed => 'Cleaning not done',
            self::DailySummary => "The day's update",
            self::ClothPickup => 'Cloths collected',
            self::ClothDelivery => 'Cloths returned',
            self::RenewalDue => 'Renewal due',
            self::RenewalOverdue => 'Renewal overdue',
            self::ClothsLow => 'Cloths running low',
            self::PutOnHold => 'Put on hold',
            self::PaymentReceipt => 'Payment receipt',
            self::Custom => 'Sent by hand',
        };
    }

    /** The provider template this maps to. */
    public function template(): string
    {
        return 'eswachh_'.$this->value;
    }

    /**
     * Is this one message per customer per day, or one per event?
     *
     * A renewal reminder must not go twice in a day - that is the whole point
     * of the dedupe key. A cleaning-done message must, because a household with
     * two cars gets one for each, and somebody who pays twice in a day for two
     * different things should hear about both.
     *
     * So the ones tied to a specific thing happening are keyed on that thing
     * rather than on the day.
     */
    public function isPerEvent(): bool
    {
        return in_array($this, [
            self::CleaningDone,
            self::CleaningMissed,
            self::ClothPickup,
            self::ClothDelivery,
            self::ComplaintRaised,
            self::CleanerAssigned,
            self::SubscriptionStarted,
            self::SubscriptionStartedAdmin,
            self::Renewed,
            self::ClothTopUp,
            self::PaymentReceipt,
        ], true);
    }
}
