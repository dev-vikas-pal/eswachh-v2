<?php

namespace Database\Seeders;

use App\Support\Settings\PolicyText;
use App\Support\Settings\SiteSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Writes the policy pages into the settings table.
 *
 * The defaults in PolicyText cover a business that has never opened the
 * settings screen. This is for the other case: somebody opened it and pressed
 * Save while those boxes were still empty, which stored three empty strings -
 * and an empty stored value now means "cleared on purpose", so the pages went
 * blank.
 *
 * Only blanks are filled. A page somebody has actually written is never
 * overwritten, so this is safe to run again.
 */
class PolicySeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            'privacy_policy' => PolicyText::PRIVACY,
            'terms' => PolicyText::TERMS,
            'refund_policy' => PolicyText::REFUNDS,
        ];

        $filled = 0;

        foreach ($pages as $key => $text) {
            $stored = DB::table('site_settings')->where('key', $key)->value('value');

            if (is_string($stored) && trim($stored) !== '') {
                $this->command?->line("  {$key}: already written, left alone.");

                continue;
            }

            SiteSettings::put([$key => $text]);
            $filled++;
        }

        $this->command?->info($filled === 0
            ? 'All three policy pages were already written.'
            : "Filled {$filled} policy page(s).");
    }
}
