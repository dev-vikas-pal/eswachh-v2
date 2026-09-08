<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes the price back out of the three renewal messages.
 *
 * The migration before this one put `{amount}` into them, and it was wrong.
 * `{amount}` resolves from `subscriptions.amount_paise` - what the plan cost the
 * last time it was bought - while the page the Renew now button opens prices the
 * plan through the price book at today's rates. On the real data those two
 * disagree on 26 of the 46 overdue plans, so most of these messages would have
 * named a figure the page then contradicted a tap later. That is the
 * ₹225-on-the-page, ₹1,872-at-the-gateway bug in a new place.
 *
 * Quoting the price book when the message is sent would fix today and break
 * later. These messages sit in a WhatsApp thread for weeks and the rule is that
 * a price is worked out at the moment it is charged - so any figure written into
 * a permanent message is a quote that will be thrown away. The button opens the
 * page that prices it properly, itemised, and that is the only figure the
 * customer is ever shown.
 *
 * Only rows still holding exactly what the previous migration wrote are
 * touched. Anything reworded in the office since is left alone.
 */
return new class extends Migration
{
    /**
     * key => [what the migration before this wrote, what it should say].
     *
     * @return array<string, array{old: string, new: string}>
     */
    private function wording(): array
    {
        return [
            'renewal_due' => [
                'old' => "Dear {name},\n"
                    ."Your car subscription for {car} expires on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Renewing now costs Rs {amount}, and 3 or 6 months saves you more.\n"
                    ."Tap Renew now below - it opens your plan with the price ready, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
                'new' => "Dear {name},\n"
                    ."Your car subscription for {car} expires on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Tap Renew now below - it opens your plan with the price worked out, and there is "
                    ."nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
            ],
            'renewal_overdue' => [
                'old' => "Dear {name},\n"
                    ."Your car subscription for {car} expired on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Renewing now costs Rs {amount}, and 3 or 6 months saves you more.\n"
                    ."Tap Renew now below - it opens your plan with the price ready, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
                'new' => "Dear {name},\n"
                    ."Your car subscription for {car} expired on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Tap Renew now below - it opens your plan with the price worked out, and there is "
                    ."nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
            ],
            'put_on_hold' => [
                'old' => "Dear {name},\n"
                    ."Cleaning for {car} has been paused because the plan was not renewed on {renew_date}.\n"
                    ."Renew for Rs {amount} and we will start again from the next round.\n"
                    ."Tap Renew now below - it opens your plan with the price ready, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
                'new' => "Dear {name},\n"
                    ."Cleaning for {car} has been paused because the plan was not renewed on {renew_date}.\n"
                    ."Tap Renew now below and we will start again from the next round - it opens your plan "
                    ."with the price worked out, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->wording() as $key => $wording) {
            DB::table('message_templates')
                ->where('key', $key)
                ->where('body', $wording['old'])
                ->update(['body' => $wording['new']]);
        }
    }

    public function down(): void
    {
        foreach ($this->wording() as $key => $wording) {
            DB::table('message_templates')
                ->where('key', $key)
                ->where('body', $wording['new'])
                ->update(['body' => $wording['old']]);
        }
    }
};
