<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The words that used to be written into the templates.
 *
 * Seeded rather than left in the markup so the office can change them without a
 * release. Safe to run more than once: it fills an empty table and leaves an
 * edited one alone, so re-seeding never overwrites what somebody has since
 * written.
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        if (Banner::query()->count() === 0) {
            Banner::create([
                'eyebrow' => 'Doorstep car cleaning',
                'headline' => 'Your car, cleaned before you leave for work.',
                'subheadline' => 'A cleaner comes to your parking spot every day. '
                    .'No queues, no drive to the wash, no wondering whether it got done.',
                'cta_label' => 'See your price',
                'cta_route' => 'subscribe',
                'secondary_label' => 'What is included',
                'secondary_route' => 'packages',
                'image_path' => '/images/banners/hero.png',
                'sort_order' => 0,
                'status' => true,
            ]);

            Banner::create([
                'eyebrow' => 'Every single morning',
                'headline' => 'The same cleaner. The same time. Every day.',
                'subheadline' => 'No app to open and no slot to book. They know your car and where you park it.',
                'cta_label' => 'See your price',
                'cta_route' => 'subscribe',
                'secondary_label' => 'Meet the team',
                'secondary_route' => 'team',
                'image_path' => '/images/banners/daily-clean.jpg',
                'sort_order' => 1,
                'status' => true,
            ]);

            Banner::create([
                'eyebrow' => 'Before the rains',
                'headline' => 'Monsoon is hard on a car. We are ready for it.',
                'subheadline' => 'Door seals, sunroof drains and wiper blades checked as part of the round.',
                'cta_label' => 'Start a plan',
                'cta_route' => 'subscribe',
                'secondary_label' => 'Read the guide',
                'secondary_route' => 'blog',
                'image_path' => '/images/banners/monsoon.jpg',
                'sort_order' => 2,
                'status' => true,
            ]);

            $this->command->info('3 home banners created.');
        }

        if (Faq::query()->count() === 0) {
            $faqs = [
                [
                    'question' => 'What time does the cleaner come?',
                    'answer' => 'Before you leave for work. You give us a preferred time when you sign up, '
                        .'and the round is built around it.',
                ],
                [
                    'question' => 'What if my car is not there that day?',
                    'answer' => 'The cleaner records that the car was away. It is not counted as a missed clean, '
                        .'and it is kept separate from cleans we actually failed to do.',
                ],
                [
                    'question' => 'Is a lot of water used?',
                    'answer' => 'Very little. Cleaning is done with wet and dry microfibre cloths, '
                        .'which is why it works in a basement car park.',
                ],
                [
                    'question' => 'What happens when my plan runs out?',
                    'answer' => 'We message you a week before the renewal date, three days before, on the day, '
                        .'and again if it lapses. If nothing is renewed a week after it ends, the service pauses. '
                        .'It does not silently keep running, and it does not stop without warning.',
                ],
                [
                    'question' => 'Can I pause or stop?',
                    'answer' => 'Yes. Call the office and we will pause it from the next day. Any cloths left on '
                        .'your plan are written off, and that is recorded so it can be explained if you come back.',
                ],
                [
                    'question' => 'How do I complain about a clean?',
                    'answer' => 'Raise it from your account or call us. Every complaint gets a reference number, '
                        .'a named person, and a time we have promised to answer by. Everything said about it is kept.',
                ],
                [
                    'question' => 'Why does my society cost more than my neighbour\'s?',
                    'answer' => 'Some addresses take longer to reach: a basement in a tower block is not the same '
                        .'as a house on a lane. Any surcharge is shown on the price before you pay, itemised '
                        .'alongside everything else.',
                ],
            ];

            foreach ($faqs as $index => $faq) {
                Faq::create($faq + ['sort_order' => $index, 'status' => true]);
            }

            $this->command->info(count($faqs).' questions created.');
        }
    }
}
