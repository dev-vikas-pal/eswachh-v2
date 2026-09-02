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

            /*
             * How often a customer hears about it, per state.
             *
             * Both daily by default, because that is how the business runs it:
             * once a plan has run out the customer is asked every day until
             * they renew, and once it is paused they are asked every day until
             * they renew or say they are finished. The message changes when the
             * status does; the rhythm does not.
             *
             * Two numbers rather than one so the two halves can be tuned apart
             * - chasing a plan that is still being cleaned is a different
             * conversation from chasing one where the cleaning has stopped, and
             * the office may well decide the first can be gentler.
             */
            'reminder_gap_overdue_days' => [
                'label' => 'Days between renewal reminders',
                'group' => 'Service',
                'rules' => ['nullable', 'integer', 'min:1', 'max:60'],
                'default' => '1',
            ],
            'reminder_gap_hold_days' => [
                'label' => 'Days between reminders once paused',
                'group' => 'Service',
                'rules' => ['nullable', 'integer', 'min:1', 'max:60'],
                'default' => '1',
            ],
            // The document says five. v1 warned at ten in one place and five in
            // another; this is the one number both now read.
            'cloth_low_threshold' => ['label' => 'Warn when cloths fall below', 'group' => 'Service', 'rules' => ['nullable', 'integer', 'min:0', 'max:500'], 'default' => '5'],

            /*
             * Where "a new car needs a cleaner" goes. v1 had this number
             * written into two controllers, so changing it meant a deployment
             * and missing one of them meant messages going to the wrong phone.
             */
            'admin_notify_phone' => ['label' => 'Number for new plan alerts', 'group' => 'Service', 'rules' => ['nullable', 'string', 'max:20'], 'default' => ''],

            /*
             * When the day's round is reported to customers.
             *
             * Everything the cleaner does used to message the customer the
             * moment they tapped it, which on an early round is six in the
             * morning - and a household with two cars was woken twice. One
             * message, at a civilised hour, says the same thing.
             */
            'daily_summary_hour' => [
                'label' => 'Hour to send the day\'s update (0-23)',
                'group' => 'Service',
                'rules' => ['nullable', 'integer', 'min:0', 'max:23'],
                'default' => '19',
            ],

            /*
             * A switch for the whole business, for when a client asks that
             * franchises stop changing what customers are on.
             *
             * Deliberately a flag as well as a role. A custom role can already
             * take update.subscription away from one franchise - that is the
             * fine-grained answer - but "nobody but the office changes a plan,
             * everywhere, from today" is a policy about the business, and
             * expressing it by editing every franchise's role one at a time is
             * how one gets missed.
             *
             * It never restricts an administrator: somebody has to be able to
             * correct a plan, and if this locked everyone out the only way back
             * would be the database.
             */
            'lock_plan_edits_to_admin' => [
                'label' => 'Only administrators may change a plan',
                'group' => 'Service',
                'rules' => ['sometimes', 'boolean'],
                'default' => '0',
                'boolean' => true,
            ],

            /*
             * The two pages a payment gateway asks to see before it will let a
             * business take money, and which v1 had as fixed templates nobody
             * could edit. Held as settings rather than as blog posts: they are
             * not articles, they have no author and no date, and they must
             * always exist.
             */
            /*
             * What search engines and a shared link show.
             *
             * Held here rather than written into the page, so a title can be
             * changed without a release - and so the person who cares about it
             * can change it without asking a developer.
             */
            'seo_title' => ['label' => 'Page title', 'group' => 'Search & sharing', 'rules' => ['nullable', 'string', 'max:70'], 'default' => 'Eswachh · Doorstep car cleaning, every day'],
            'seo_description' => ['label' => 'Description', 'group' => 'Search & sharing', 'rules' => ['nullable', 'string', 'max:180'], 'default' => 'Daily doorstep car cleaning at your parking spot. Pick a plan, pick how often, and the same cleaner comes every day before you leave for work.', 'long' => true],
            'seo_keywords' => ['label' => 'Keywords', 'group' => 'Search & sharing', 'rules' => ['nullable', 'string', 'max:255'], 'default' => 'car cleaning, doorstep car wash, daily car cleaning, Greater Noida'],
            // The picture that appears when somebody shares a link.
            'seo_share_image' => ['label' => 'Sharing image', 'group' => 'Search & sharing', 'rules' => ['nullable', 'string', 'max:255'], 'default' => ''],
            'seo_index' => [
                'label' => 'Let search engines list this site',
                'group' => 'Search & sharing',
                'rules' => ['sometimes', 'boolean'],
                // On, but switchable: a staging copy that gets indexed competes
                // with the real site for its own name.
                'default' => '1',
                'boolean' => true,
            ],

            /*
             * The cloth ironing service, business-wide.
             *
             * Built on both sides and working, but switched off while the
             * business decides whether to run it. Off hides the top-up page,
             * the cloth screens and the cloth choice on the signup form; it
             * does not touch existing balances or the ledger, so turning it
             * back on resumes exactly where it left off.
             */
            'cloth_service_enabled' => [
                'label' => 'Offer the cloth ironing service',
                'group' => 'Service',
                'rules' => ['sometimes', 'boolean'],
                'default' => '0',
                'boolean' => true,
            ],

            /*
             * The blog and the team page, business-wide.
             *
             * Both are built and both work; neither had anything worth showing
             * at launch, and a site whose Advice section holds two placeholder
             * articles looks worse than a site with no Advice section. Off
             * hides them everywhere at once - the public menu and its routes,
             * the public endpoints, the office screen, and the master lists
             * behind them - so a search engine cannot go on serving an article
             * that the menu no longer offers.
             *
             * Nothing is deleted. Turning either back on is this checkbox, and
             * every article, category, tag and team member is where it was.
             */
            'blog_enabled' => [
                'label' => 'Publish the advice blog',
                'group' => 'Service',
                'rules' => ['sometimes', 'boolean'],
                'default' => '0',
                'boolean' => true,
            ],
            'team_enabled' => [
                'label' => 'Show the team page',
                'group' => 'Service',
                'rules' => ['sometimes', 'boolean'],
                'default' => '0',
                'boolean' => true,
            ],

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
        $cached = Cache::rememberForever(self::CACHE_KEY, fn () => self::read());

        /*
         * A setting added since the cache was written would otherwise be
         * missing until somebody cleared it by hand - which is exactly what
         * happened when the SEO fields were added and the site kept serving
         * the fallback title. Deploying a new setting should not need a cache
         * clear to take effect, so a cache that predates one is rebuilt.
         */
        if (count($cached) !== count(self::definitions())) {
            Cache::forget(self::CACHE_KEY);

            return Cache::rememberForever(self::CACHE_KEY, fn () => self::read());
        }

        return $cached;
    }

    /**
     * Straight from the table, with defaults filled in.
     *
     * @return array<string, string>
     */
    private static function read(): array
    {
        $stored = DB::table('site_settings')->pluck('value', 'key')->all();
        $out = [];

        foreach (self::definitions() as $key => $definition) {
            /*
             * array_key_exists rather than ??, because a stored null means
             * something different from no row at all: somebody cleared this
             * field on purpose. With ?? the two are the same, so emptying a
             * policy page and saving would silently bring the shipped wording
             * back and look like the save had failed.
             */
            $out[$key] = array_key_exists($key, $stored)
                ? ($stored[$key] ?? '')
                : $definition['default'];
        }

        return $out;
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
