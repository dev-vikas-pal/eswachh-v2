<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * The wording the business actually sends.
 *
 * All twelve messages set out in the requirements document, worded as written
 * there. v1 sent every one of these; carrying them across is not new work so
 * much as not losing it.
 *
 * Seeded rather than left in code so the office can change a word without a
 * release. Safe to run again: it adds templates that are missing and leaves
 * every existing one alone, so re-seeding never overwrites an edit.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $added = 0;

        foreach ($this->templates() as $template) {
            // Keyed on `key`, so a template somebody has since rewritten is
            // never touched and a newly added one still arrives.
            if (MessageTemplate::query()->where('key', $template['key'])->exists()) {
                continue;
            }

            MessageTemplate::create($template + [
                'channel' => 'whatsapp',
                'placeholders' => MessageTemplate::availablePlaceholders(),
                'status' => true,
            ]);

            $added++;
        }

        $this->command?->info($added === 0
            ? 'Message templates were already in place.'
            : "Added {$added} message template(s).");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            // ------------------------------------------------------- [1] & [2]
            [
                'key' => 'subscription_started',
                'name' => 'New plan started',
                'description' => 'Sent to the customer when a new plan is paid for.',
                'provider_template' => 'eswachh_subscription_started',
                /*
                 * How to sign in goes in the welcome, not a separate message.
                 *
                 * v1 generated a password and mailed it. There is no password
                 * here - a code to this number is the way in - so the useful
                 * thing to send is where to go and what to expect, and the
                 * moment somebody actually wants it is now.
                 */
                'body' => "Dear {name},\n"
                    ."Thanks for subscribing to our {package} daily car cleaning plan for {months} month.\n"
                    ."Car no - {car}\n"
                    ."Cloth ironing plan - {cloth_plan}\n"
                    ."We will update you once a cleaner is assigned to your car.\n"
                    ."To see your plan, sign in at {site}/login with this number - we send a code, "
                    ."there is no password to remember.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],
            [
                'key' => 'subscription_started_admin',
                'name' => 'New plan, told the office',
                'description' => 'Sent to the office number so somebody assigns a cleaner.',
                'provider_template' => 'eswachh_subscription_started_admin',
                'body' => "Dear Admin,\n"
                    ."New car {car} subscribed to {package} plan for {months} month. Please assign a cleaner to them.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ------------------------------------------------------------ [3]
            [
                'key' => 'renewed',
                'name' => 'Plan renewed',
                'description' => 'Sent to the customer when a plan is renewed.',
                'provider_template' => 'eswachh_renewed',
                'body' => "Dear {name},\n"
                    ."Thanks for renewing the {package} daily car cleaning plan for {months} month.\n"
                    ."Car no - {car}\n"
                    ."Cloth ironing plan - {cloth_plan}\n"
                    ."Please complain on the same day when there is any gap or issue.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ------------------------------------------------------------ [4]
            [
                'key' => 'cloth_top_up',
                'name' => 'Cloths topped up',
                'description' => 'Sent when a customer buys more cloths.',
                'provider_template' => 'eswachh_cloth_top_up',
                'body' => "Dear {name},\n"
                    ."Thanks, top-up done for the {cloth_plan} cloth count plan.\n"
                    ."Current cloth count - {cloths}\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ------------------------------------------------------------ [5]
            [
                'key' => 'complaint_raised',
                'name' => 'Complaint raised',
                'description' => 'Sent to the cleaners when a customer raises a complaint.',
                'provider_template' => 'eswachh_complaint_raised',
                'body' => "Dear Cleaners,\n"
                    ."There is a complaint for car {car} today. Please talk to them and confirm here once it is resolved.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ------------------------------------------------------------ [6]
            [
                'key' => 'cleaner_assigned',
                'name' => 'Cleaner assigned',
                'description' => 'Sent to the customer when somebody is given their car.',
                'provider_template' => 'eswachh_cleaner_assigned',
                'body' => "Dear {name},\n"
                    ."A cleaner has been assigned to your car {car} for the daily car cleaning service.\n"
                    ."Cleaner name - {cleaner}\n"
                    ."Cleaner no - {cleaner_phone}\n"
                    ."Please complain on the same day when there is any gap or issue.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ------------------------------------------------------------ [7]
            [
                'key' => 'custom',
                'name' => 'A message of your own',
                'description' => 'Whatever the office needs to say, to one customer or many.',
                'provider_template' => 'eswachh_custom',
                'body' => "Dear {name},\n{message}\nThanks,\nTeam eSwachh",
                'bulk_sendable' => true,
            ],

            // ------------------------------------------------------------ [8]
            [
                'key' => 'cleaning_done',
                'name' => 'Cleaning done',
                'description' => 'Sent when the cleaner marks the car done.',
                'provider_template' => 'eswachh_cleaning_done',
                'body' => "Dear {name},\n"
                    ."Cleaning has been done today for your car {car}.\n"
                    ."Please complain on the same day when there is any gap or issue.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            /*
             * The other half of [8]. A customer who hears nothing on a day the
             * cleaner came and could not do the work assumes nobody turned up,
             * and the office answers that complaint with no record of what
             * actually happened.
             */
            [
                'key' => 'cleaning_missed',
                'name' => 'Cleaning not done',
                'description' => 'Sent when the cleaner records that the car could not be cleaned.',
                'provider_template' => 'eswachh_cleaning_missed',
                'body' => "Dear {name},\n"
                    ."We could not clean your car {car} today because {reason}.\n"
                    ."We will be back on the next scheduled day. Call us if that is not right.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            /*
             * The day's round, told once, at an hour somebody is awake.
             *
             * {round} is built by the summary job: one line per car, so a
             * household with two hears about both in one message instead of
             * being woken twice at six in the morning.
             */
            [
                'key' => 'daily_summary',
                'name' => "The day's update",
                'description' => "Sent once a day with what happened to each of the customer's cars.",
                'provider_template' => 'eswachh_daily_summary',
                'body' => "Dear {name},\n"
                    ."Here is today's update:\n"
                    ."{round}\n"
                    ."Please tell us the same day if anything is not right.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ------------------------------------------------------- [9] & [10]
            [
                'key' => 'cloth_pickup',
                'name' => 'Cloths collected',
                'description' => 'Sent when cloths are picked up.',
                'provider_template' => 'eswachh_cloth_pickup',
                'body' => "Dear {name},\n"
                    ."Cloth pickup has been done on {date}.\n"
                    ."Pickup count - {count}\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],
            [
                'key' => 'cloth_delivery',
                'name' => 'Cloths returned',
                'description' => 'Sent when cloths are delivered back.',
                'provider_template' => 'eswachh_cloth_delivery',
                'body' => "Dear {name},\n"
                    ."Cloth delivery has been done on {date}.\n"
                    ."Delivery count - {count}\n"
                    ."Balance - {cloths}\n"
                    ."Cloth pickup and delivery will stop when the balance is less than 10.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],

            // ----------------------------------------------------------- [11]
            [
                'key' => 'renewal_due',
                'name' => 'Renewal due',
                'description' => 'Sent a week before, three days before, and on the renewal date.',
                'provider_template' => 'eswachh_renewal_due',
                'body' => "Dear {name},\n"
                    ."Your car subscription expires on {renew_date}.\n"
                    ."There is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Go to the renew section at https://www.eswachh.in\n"
                    ."You can renew for 3/6 months to save 75/300.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => true,
            ],
            [
                'key' => 'renewal_overdue',
                'name' => 'Renewal overdue',
                'description' => 'Sent after the renewal date has passed.',
                'provider_template' => 'eswachh_renewal_overdue',
                'body' => "Dear {name},\n"
                    ."Your car subscription expired on {renew_date}.\n"
                    ."As per the system in place there is a 1 week grace period, after which cleaning will stop, so please renew.\n"
                    ."Go to the renew section at https://www.eswachh.in\n"
                    ."You can renew for 3/6 months to save 75/300.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => true,
            ],

            // ----------------------------------------------------------- [12]
            [
                'key' => 'cloths_low',
                'name' => 'Cloths running low',
                'description' => 'Sent when the cloth balance runs down.',
                'provider_template' => 'eswachh_cloths_low',
                'body' => "Dear {name},\n"
                    ."Your cloth count balance is now lower than 10. Cloth pickup and delivery are stopped now.\n"
                    ."Please top up your cloth count from the website.\n"
                    ."https://www.eswachh.in\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => true,
            ],

            // ----------------------------------- v1 also had these two, so keep
            [
                'key' => 'put_on_hold',
                'name' => 'Plan paused',
                'description' => 'Sent when a plan is paused for non-renewal.',
                'provider_template' => 'eswachh_put_on_hold',
                'body' => "Dear {name},\n"
                    ."Cleaning for {car} has been paused because the plan was not renewed.\n"
                    ."Renew any time and we will start again from the next day.\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => true,
            ],
            [
                'key' => 'payment_receipt',
                'name' => 'Payment received',
                'description' => 'Sent when a payment is captured.',
                'provider_template' => 'eswachh_payment_receipt',
                // Not offered in the bulk picker: a receipt for money that was
                // not just taken is worse than no receipt.
                //
                // {paid_amount} rather than {amount}: the first is what was
                // taken, the second is what the plan costs today, and on a
                // receipt those are not interchangeable.
                'body' => "Dear {name},\n"
                    ."We have received Rs {paid_amount} for {car} on {paid_on}.\n"
                    ."Receipt no - {invoice_number}\n"
                    ."The plan now runs to {renew_date}.\n"
                    ."Your bill: {invoice_link}\n"
                    ."Thanks,\nTeam eSwachh",
                'bulk_sendable' => false,
            ],
        ];
    }
}
