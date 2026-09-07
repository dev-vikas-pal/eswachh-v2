<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes that arrive together because they ship the same feature: the
 * renewal messages get a button that pays, and every template this system owns
 * gets a name of its own at the provider.
 */
return new class extends Migration
{
    /**
     * The renames, old => new.
     *
     * The seeder cannot do this. It is keyed on `key` and skips a template that
     * already exists, deliberately - re-seeding must never overwrite wording the
     * office has changed - so on every database that has already been seeded the
     * old names would simply stay.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
        'eswachh_subscription_started' => 'v2_subscription_started',
        'eswachh_subscription_started_admin' => 'v2_subscription_started_admin',
        'eswachh_renewed' => 'v2_renewed',
        'eswachh_cloth_top_up' => 'v2_cloth_top_up',
        'eswachh_complaint_raised' => 'v2_complaint_raised',
        'eswachh_cleaner_assigned' => 'v2_cleaner_assigned',
        'eswachh_custom' => 'v2_custom',
        'eswachh_cleaning_done' => 'v2_cleaning_done',
        'eswachh_cleaning_missed' => 'v2_cleaning_missed',
        'eswachh_daily_summary' => 'v2_daily_summary',
        'eswachh_cloth_pickup' => 'v2_cloth_pickup',
        'eswachh_cloth_delivery' => 'v2_cloth_delivery',
        'eswachh_cloths_low' => 'v2_cloths_low',
        'eswachh_payment_receipt' => 'v2_payment_receipt',

        /*
         * These two are not renames of ours - they are v1's own templates,
         * which this system was borrowing because they were the only approved
         * wording that would carry.
         *
         * v1 is still live and still sending from both of them. Pointing at our
         * own from here is what makes a button possible at all: adding one to
         * `hold_cars` would change what v1 sends the same afternoon.
         */
        'subscription_expire' => 'v2_renewal_overdue',
        'hold_cars' => 'v2_put_on_hold',
    ];

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            /*
             * What the Renew now button carries.
             *
             * A short random string rather than a signed URL, because Meta
             * appends a button's variable to the end of a fixed address and
             * nothing else: the approved template holds
             * `https://eswachh.in/r/{{1}}`, so whatever identifies the plan has
             * to fit on the end of a path with no query string on it. A signed
             * route puts its signature in the query string and could not be
             * used here.
             *
             * Nullable and filled on first use, so no backfill is needed and a
             * plan that has never been chased carries nothing to leak.
             */
            $table->string('renew_token', 32)->nullable()->unique()->after('ended_at');
        });

        /*
         * A template nobody has renamed by hand. Matched on the old value, so
         * one already pointed somewhere else is left exactly as it is.
         */
        foreach (self::RENAMES as $old => $new) {
            DB::table('message_templates')
                ->where('provider_template', $old)
                ->update(['provider_template' => $new]);
        }

        /*
         * `renewal_due` has always held null: there was no approved template
         * for a reminder before the date, so nothing sent it. It has one now.
         */
        DB::table('message_templates')
            ->where('key', 'renewal_due')
            ->whereNull('provider_template')
            ->update(['provider_template' => 'v2_renewal_due']);

        $this->refreshUntouchedRenewalWording();
    }

    /**
     * Bring the three renewal messages up to wording that mentions the button.
     *
     * Only where the body is still exactly what was shipped. An office that has
     * rewritten one has said what they want it to say, and a migration is the
     * last place that should be argued with - so those are left alone, and the
     * next export simply carries whatever they wrote.
     *
     * Nothing here is required for the button to work. The export builds its
     * variables from whatever the body is, so an old body exports and sends
     * perfectly well; it just does not tell the customer the button is there.
     */
    private function refreshUntouchedRenewalWording(): void
    {
        $shipped = [
            'renewal_due' => [
                'old' => "Dear {name},\n"
                    ."Your car subscription expires on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Go to the renew section at https://www.eswachh.in\n"
                    ."You can renew for 3/6 months to save 75/300.\n"
                    ."Thanks,\nTeam eSwachh",
                'new' => "Dear {name},\n"
                    ."Your car subscription for {car} expires on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Renewing now costs Rs {amount}, and 3 or 6 months saves you more.\n"
                    ."Tap Renew now below - it opens your plan with the price ready, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
            ],
            'renewal_overdue' => [
                'old' => "Dear {name},\n"
                    ."Your car subscription expired on {renew_date}.\n"
                    ."As per the system in place there is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Go to the renew section at https://www.eswachh.in\n"
                    ."You can renew for 3/6 months to save 75/300.\n"
                    ."Thanks,\nTeam eSwachh",
                'new' => "Dear {name},\n"
                    ."Your car subscription for {car} expired on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Renewing now costs Rs {amount}, and 3 or 6 months saves you more.\n"
                    ."Tap Renew now below - it opens your plan with the price ready, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
            ],
            'put_on_hold' => [
                'old' => "Dear {name},\n"
                    ."Cleaning for {car} has been paused because the plan was not renewed.\n"
                    ."Renew any time and we will start again from the next day.\n"
                    ."Thanks,\nTeam eSwachh",
                'new' => "Dear {name},\n"
                    ."Cleaning for {car} has been paused because the plan was not renewed on {renew_date}.\n"
                    ."Renew for Rs {amount} and we will start again from the next round.\n"
                    ."Tap Renew now below - it opens your plan with the price ready, and there is nothing to sign in to.\n"
                    ."Thanks,\nTeam eSwachh",
            ],
        ];

        foreach ($shipped as $key => $wording) {
            DB::table('message_templates')
                ->where('key', $key)
                ->where('body', $wording['old'])
                ->update(['body' => $wording['new']]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['renew_token']);
            $table->dropColumn('renew_token');
        });

        foreach (self::RENAMES as $old => $new) {
            DB::table('message_templates')
                ->where('provider_template', $new)
                ->update(['provider_template' => $old]);
        }

        DB::table('message_templates')
            ->where('key', 'renewal_due')
            ->where('provider_template', 'v2_renewal_due')
            ->update(['provider_template' => null]);
    }
};
