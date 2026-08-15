<?php

namespace App\Support\Access;

/**
 * Every ability the application checks, grouped the way somebody thinks about
 * the business rather than the way the code is arranged.
 *
 * This is the list a custom role is built from, so it has to be complete: an
 * ability checked somewhere but missing here can never be granted, and the
 * screen that uses it is silently unreachable for every custom role.
 *
 * There is a test that walks the source for `authorize(...)` and `can:` and
 * fails if it finds one that is not listed here, so the two cannot drift.
 */
final class Abilities
{
    /**
     * Module => [ability => what it lets somebody do].
     *
     * @return array<string, array<string, string>>
     */
    public static function catalogue(): array
    {
        return [
            'Dashboard' => [
                'view.dashboard' => 'See the dashboard',
            ],

            'Subscriptions' => [
                'view.subscription' => 'See plans',
                'create.subscription' => 'Start a new plan',
                'update.subscription' => 'Change a plan, pause it, renew it',
                'renew.subscription' => 'Renew their own plan',
            ],

            'Customers' => [
                'view.customer' => 'See customers',
                'create.customer' => 'Add a customer',
                'update.customer' => 'Change a customer',
                'view.vehicle' => 'See cars',
                'create.vehicle' => 'Add a car',
                'update.vehicle' => 'Change a car',
                'assign.cleaner' => 'Decide who cleans a car',
            ],

            'Money' => [
                'view.payment' => 'See payments',
                'create.payment' => 'Record a payment taken by hand',
                'view.invoice' => 'Open and print a receipt',
                'view.report' => 'See revenue and reports',
                'buy.cloth.topup' => 'Buy more cloths for their own plan',
            ],

            'Complaints' => [
                'view.complaint' => 'See complaints',
                'create.complaint' => 'Raise a complaint',
                'assign.complaint' => 'Hand a complaint to somebody',
                'resolve.complaint' => 'Say a complaint is dealt with',
                'close.complaint' => 'Close a complaint',
            ],

            'The round' => [
                'view.round' => "See the day's cars",
                'record.service' => 'Record what happened at a car',
                'view.attendance' => 'See who turned up',
                'record.attendance' => 'Record who turned up',
                'view.cloth' => 'See cloth balances',
                'update.cloth' => 'Correct a cloth balance',
                'record.cloth' => 'Record cloths picked up and delivered',
            ],

            'Staff' => [
                'view.staff' => 'See the people list',
                'create.staff' => 'Add somebody',
                'update.staff' => 'Change somebody',
            ],

            'Administration' => [
                // Deliberately grantable. A super admin delegating the masters
                // to a trusted manager is a real decision, not a mistake.
                //
                // Managing roles is not here on purpose - see grantable().
                'manage.master' => 'Edit masters, site settings, blog, backups and logs',
            ],
        ];
    }

    /**
     * Flat list of every ability name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::catalogue())));
    }

    /**
     * Abilities a custom role may be given: everything in the catalogue.
     *
     * The two powers that would matter most are not in the catalogue at all,
     * and that is the guard rather than a filter applied here:
     *
     *  - seeing across branches is not an ability. It is a property of the
     *    built-in role, checked separately, so no permission screen can grant
     *    it and no custom role can read another franchise's books;
     *  - managing roles is not an ability either. It is "are you a super
     *    admin", asked directly in the controller. If it were a checkbox, a
     *    role could be given the power to grant itself the rest.
     *
     * @return array<int, string>
     */
    public static function grantable(): array
    {
        return self::all();
    }

    public static function exists(string $ability): bool
    {
        return in_array($ability, self::all(), true);
    }

    /**
     * The module an ability belongs to, for showing a role's summary.
     */
    public static function moduleOf(string $ability): ?string
    {
        foreach (self::catalogue() as $module => $abilities) {
            if (array_key_exists($ability, $abilities)) {
                return $module;
            }
        }

        return null;
    }
}
