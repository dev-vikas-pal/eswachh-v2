<?php

namespace App\Support\Settings;

/**
 * Parts of the system the business can switch off.
 *
 * Not permissions and not configuration: these are whole features that are
 * built, tested and working, and that the business has decided not to run yet.
 * The cloth ironing service was the first; the blog and the team page joined it
 * for launch, when there was nothing worth publishing on either.
 *
 * The point of naming them here rather than reading the setting key at each
 * call site is that a feature has to disappear from *every* door at once. A
 * blog hidden from the public menu but still answering on /api/v1/public/posts
 * is not switched off, it is merely quiet - and a search engine that indexed it
 * last week will keep sending people to it.
 *
 * Turning one back on is a checkbox. Nothing is deleted, no route is removed,
 * and the articles, the team and the cloth balances are all exactly where they
 * were left.
 */
final class Features
{
    public const BLOG = 'blog';

    public const TEAM = 'team';

    public const CLOTH = 'cloth_service';

    /**
     * The setting behind each one.
     *
     * @var array<string, string>
     */
    private const SETTING = [
        self::BLOG => 'blog_enabled',
        self::TEAM => 'team_enabled',
        self::CLOTH => 'cloth_service_enabled',
    ];

    /**
     * Is this feature being offered?
     *
     * An unknown name is off. A typo in a route definition must not silently
     * open something up - that is the one failure here that nobody would
     * notice, because everything would appear to work.
     */
    public static function on(string $feature): bool
    {
        $setting = self::SETTING[$feature] ?? null;

        return $setting !== null && (bool) SiteSettings::get($setting);
    }

    /**
     * Every flag, for the front end to draw itself from.
     *
     * Sent to both doors - the public site and the office - because both have
     * menus, routes and screens that have to agree with the server about what
     * exists.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        return array_map(
            fn (string $setting) => (bool) SiteSettings::get($setting),
            self::SETTING,
        );
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::SETTING);
    }
}
