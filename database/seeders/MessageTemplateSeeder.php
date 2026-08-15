<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * The wording that used to be written into the commands.
 *
 * Seeded rather than left in code so the office can change it. Safe to run
 * again: it fills an empty table and leaves an edited one alone, so re-seeding
 * never overwrites what somebody has since rewritten.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (MessageTemplate::query()->count() > 0) {
            $this->command->info('Message templates already exist.');

            return;
        }

        $templates = [
            [
                'key' => 'renewal_due',
                'name' => 'Renewal due',
                'description' => 'Sent a week before, three days before, and on the renewal date.',
                'provider_template' => 'eswachh_renewal_due',
                'body' => 'Hello {name}, the cleaning plan for {car} is due for renewal on {renew_date}. '
                    .'Renew for Rs {amount} to keep the service running.',
                'bulk_sendable' => true,
            ],
            [
                'key' => 'renewal_overdue',
                'name' => 'Renewal overdue',
                'description' => 'Sent three days after the renewal date has passed.',
                'provider_template' => 'eswachh_renewal_overdue',
                'body' => 'Hello {name}, the cleaning plan for {car} is now overdue. '
                    .'Please renew for Rs {amount} - the service will be paused if we do not hear from you.',
                'bulk_sendable' => true,
            ],
            [
                'key' => 'put_on_hold',
                'name' => 'Plan paused',
                'description' => 'Sent when a plan is paused for non-renewal.',
                'provider_template' => 'eswachh_on_hold',
                'body' => 'Hello {name}, cleaning for {car} has been paused because the plan was not renewed. '
                    .'Renew any time and we will start again from the next day.',
                'bulk_sendable' => true,
            ],
            [
                'key' => 'cloths_low',
                'name' => 'Cloths running low',
                'description' => 'Sent when a cloth balance is nearly used up.',
                'provider_template' => 'eswachh_cloths_low',
                'body' => 'Hello {name}, there are {cloths} cloths left on the plan for {car}. '
                    .'Call {phone} to top up before they run out.',
                'bulk_sendable' => true,
            ],
            [
                'key' => 'payment_receipt',
                'name' => 'Payment received',
                'description' => 'Sent when a payment is captured.',
                'provider_template' => 'eswachh_receipt',
                // Not offered in the bulk picker: a receipt for money that was
                // not just taken is worse than no receipt.
                'body' => 'Thank you {name}. We have received Rs {amount} for {car}. '
                    .'The plan now runs to {renew_date}.',
                'bulk_sendable' => false,
            ],
        ];

        foreach ($templates as $template) {
            MessageTemplate::create($template + [
                'channel' => 'whatsapp',
                'placeholders' => MessageTemplate::availablePlaceholders(),
                'status' => true,
            ]);
        }

        $this->command->info(count($templates).' message templates created.');
    }
}
