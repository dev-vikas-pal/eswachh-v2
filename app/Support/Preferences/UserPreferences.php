<?php

namespace App\Support\Preferences;

/**
 * The interface settings a person can choose, and what they are allowed to be.
 *
 * Kept here rather than spread between a controller and a Vue component so
 * there is one list. A preference the server does not recognise is dropped
 * rather than stored: the column is JSON, and without this it would happily
 * accept anything a browser cared to post and hand it back to every future
 * session.
 */
class UserPreferences
{
    /**
     * Setting name => allowed values, first entry being the default.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        // 'system' follows the operating system's light or dark setting.
        'theme' => ['system', 'light', 'dark'],

        // Where the navigation lives.
        'menu_position' => ['top', 'left'],

        // How much breathing room the tables get.
        'density' => ['comfortable', 'compact'],

        // Whether the side menu shows words or only icons. Saved against the
        // account like the rest, so somebody who works in a narrow sidebar all
        // day does not collapse it again every morning.
        'sidebar' => ['wide', 'narrow'],
    ];

    /**
     * Fill in anything unset, and drop anything unrecognised.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, string>
     */
    public static function resolve(?array $stored): array
    {
        $resolved = [];

        foreach (self::ALLOWED as $key => $values) {
            $value = $stored[$key] ?? null;

            // A value that is no longer offered - because the options changed
            // since it was saved - falls back to the default rather than being
            // handed to the interface, which would not know what to do with it.
            $resolved[$key] = in_array($value, $values, true) ? $value : $values[0];
        }

        return $resolved;
    }

    /**
     * Validation rules for a partial update.
     *
     * Every setting is optional, so a client can change the theme without
     * having to send back settings it does not care about.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::ALLOWED as $key => $values) {
            $rules[$key] = ['sometimes', 'string', \Illuminate\Validation\Rule::in($values)];
        }

        return $rules;
    }

    /**
     * The choices, for a settings screen that should not hard-code them.
     *
     * @return array<string, array<int, string>>
     */
    public static function options(): array
    {
        return self::ALLOWED;
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::resolve(null);
    }
}
