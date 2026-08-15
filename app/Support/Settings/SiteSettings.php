<?php

namespace App\Support\Settings;

use App\Support\Content\HtmlSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Business details, editable from a screen.
 *
 * The allowed keys live here rather than being whatever somebody posts: the
 * table is key and value, so without a whitelist a client could write any key
 * it liked and the screen would slowly fill with junk nobody reads.
 *
 * Nothing secret goes in here. Gateway keys and API tokens stay in .env, where
 * they are not in a database dump that an administrator can download from the
 * backups screen. What lives here is the sort of thing that changes when the
 * business moves office.
 */
class SiteSettings
{
    private const CACHE_KEY = 'site-settings';

    /**
     * key => label, group, rules, default.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'business_name' => ['label' => 'Business name', 'group' => 'Business', 'rules' => ['nullable', 'string', 'max:120'], 'default' => 'Eswachh'],
            'legal_name' => ['label' => 'Registered name', 'group' => 'Business', 'rules' => ['nullable', 'string', 'max:160'], 'default' => ''],
            'gstin' => ['label' => 'GSTIN', 'group' => 'Business', 'rules' => ['nullable', 'string', 'max:20'], 'default' => ''],
            'address' => ['label' => 'Address', 'group' => 'Business', 'rules' => ['nullable', 'string', 'max:500'], 'default' => '', 'long' => true],

            'contact_phone' => ['label' => 'Phone', 'group' => 'Contact', 'rules' => ['nullable', 'string', 'max:20'], 'default' => ''],
            'contact_email' => ['label' => 'Email', 'group' => 'Contact', 'rules' => ['nullable', 'email', 'max:120'], 'default' => ''],
            'whatsapp_number' => ['label' => 'WhatsApp number', 'group' => 'Contact', 'rules' => ['nullable', 'string', 'max:20'], 'default' => ''],
            'office_hours' => ['label' => 'Office hours', 'group' => 'Contact', 'rules' => ['nullable', 'string', 'max:120'], 'default' => '9am to 6pm, Monday to Saturday'],

            'invoice_prefix' => ['label' => 'Invoice prefix', 'group' => 'Billing', 'rules' => ['nullable', 'string', 'max:10'], 'default' => 'ESW'],
            'invoice_footer' => ['label' => 'Invoice footer note', 'group' => 'Billing', 'rules' => ['nullable', 'string', 'max:300'], 'default' => '', 'long' => true],

            // How the business behaves, as opposed to how the code is wired.
            'renewal_grace_days' => ['label' => 'Days overdue before pausing', 'group' => 'Service', 'rules' => ['nullable', 'integer', 'min:0', 'max:60'], 'default' => '7'],
            'cloth_low_threshold' => ['label' => 'Warn when cloths fall below', 'group' => 'Service', 'rules' => ['nullable', 'integer', 'min:0', 'max:500'], 'default' => '10'],

            /*
             * The two pages a payment gateway asks to see before it will let a
             * business take money, and which v1 had as fixed templates nobody
             * could edit. Held as settings rather than as blog posts: they are
             * not articles, they have no author and no date, and they must
             * always exist.
             */
            'privacy_policy' => ['label' => 'Privacy policy', 'group' => 'Policies', 'rules' => ['nullable', 'string', 'max:20000'], 'default' => PolicyText::PRIVACY, 'rich' => true],
            'terms' => ['label' => 'Terms of service', 'group' => 'Policies', 'rules' => ['nullable', 'string', 'max:20000'], 'default' => PolicyText::TERMS, 'rich' => true],
            'refund_policy' => ['label' => 'Cancellation and refunds', 'group' => 'Policies', 'rules' => ['nullable', 'string', 'max:20000'], 'default' => PolicyText::REFUNDS, 'rich' => true],
        ];
    }

    /**
     * Every setting, with defaults filled in.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = DB::table('site_settings')->pluck('value', 'key')->all();
            $out = [];

            foreach (self::definitions() as $key => $definition) {
                /*
                 * array_key_exists rather than ??, because a stored null means
                 * something different from no row at all: somebody cleared this
                 * field on purpose. With ?? the two are the same, so emptying a
                 * policy page and saving would silently bring the shipped
                 * wording back and look like the save had failed.
                 */
                $out[$key] = array_key_exists($key, $stored)
                    ? ($stored[$key] ?? '')
                    : $definition['default'];
            }

            return $out;
        });
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::all()[$key] ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values): void
    {
        $known = self::definitions();

        foreach ($values as $key => $value) {
            // A key not on the list is dropped rather than stored, so the table
            // only ever holds settings something actually reads.
            if (! isset($known[$key])) {
                continue;
            }

            /*
             * Cleaned on the way in, not on the way out. The policy pages are
             * the only settings rendered as markup, and sanitising at render
             * time would leave the mess in the table for every future reader to
             * remember to clean - one that forgets is a hole. Cleaned here, the
             * stored value is already safe.
             */
            if ($known[$key]['rich'] ?? false) {
                $value = HtmlSanitizer::clean(is_string($value) ? $value : null);
            }

            DB::table('site_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value === null ? null : (string) $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Cleared rather than rewritten: the next read rebuilds from the table,
        // so a half-finished write cannot leave a wrong value cached forever.
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::definitions() as $key => $definition) {
            $rules[$key] = array_merge(['sometimes'], $definition['rules']);
        }

        return $rules;
    }
}
