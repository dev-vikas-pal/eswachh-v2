<?php

namespace App\Support\Masters;

use App\Models\Area;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\City;
use App\Models\ClothBundle;
use App\Models\Duration;
use App\Models\Faq;
use App\Models\MessageTemplate;
use App\Models\Package;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Society;
use App\Models\State;
use App\Models\TeamMember;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use InvalidArgumentException;

/**
 * The reference lists behind everything else.
 *
 * All edited the same way: a name, sometimes a price, sometimes a parent. One
 * registry rather than twenty near-identical controllers, because twenty copies
 * of the same CRUD is twenty places for a validation rule to be forgotten - and
 * several of these carry prices, where a missing rule means a mistyped master
 * silently re-prices every plan that uses it.
 *
 * Only names listed here can be addressed, so the route cannot be pointed at an
 * arbitrary table.
 */
class MasterRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            /*
             * There is no franchise master, deliberately.
             *
             * A sector *is* the territory, and the business has no separate
             * franchise with a meaning of its own. One briefly existed here and
             * was removed: it added a level that had to be kept in step with
             * the sectors beneath it, and keeping it in step was the bug.
             */

            // ---- geography, in the order one depends on the next ----
            'states' => [
                'model' => State::class,
                'label' => 'States',
                'singular' => 'State',
                'group' => 'Geography',
                'fields' => ['name' => ['required', 'string', 'max:120']],
            ],
            'cities' => [
                'model' => City::class,
                'label' => 'Cities',
                'singular' => 'City',
                'group' => 'Geography',
                'parent' => ['key' => 'state_id', 'master' => 'states', 'label' => 'State'],
                'fields' => ['name' => ['required', 'string', 'max:120']],
            ],
            'areas' => [
                'model' => Area::class,
                'label' => 'Areas',
                'singular' => 'Area',
                'group' => 'Geography',
                'parent' => ['key' => 'city_id', 'master' => 'cities', 'label' => 'City'],
                'fields' => ['name' => ['required', 'string', 'max:120']],
            ],
            'sectors' => [
                'model' => Sector::class,
                'label' => 'Sectors',
                'singular' => 'Sector',
                'group' => 'Geography',
                'parent' => ['key' => 'area_id', 'master' => 'areas', 'label' => 'Area'],
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                ],
                /*
                 * Who covers it. The one master with an operational consequence:
                 * this is the whole of tenancy, so it is edited on the sector
                 * itself rather than being buried on a staff screen.
                 *
                 * Not a plain field - it is rows in user_sector, written after
                 * the sector is saved by the master controller's syncStaff.
                 */
                'staff' => true,
            ],
            'societies' => [
                'model' => Society::class,
                'label' => 'Societies',
                'singular' => 'Society',
                'group' => 'Geography',
                'parent' => ['key' => 'sector_id', 'master' => 'sectors', 'label' => 'Sector'],
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    // Charged every month, so a typo here is not a one-off.
                    'surcharge_paise' => ['required', 'integer', 'min:0', 'max:10000000'],
                ],
                'money' => ['surcharge_paise'],
            ],

            // ---- the price list ----
            'vehicle-categories' => [
                'model' => VehicleCategory::class,
                'label' => 'Vehicle types',
                'singular' => 'Vehicle type',
                'group' => 'Price list',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'price_paise' => ['required', 'integer', 'min:0', 'max:10000000'],
                ],
                'money' => ['price_paise'],
            ],
            'vehicle-models' => [
                'model' => VehicleModel::class,
                'label' => 'Car models',
                'singular' => 'Car model',
                'group' => 'Price list',
                'parent' => ['key' => 'vehicle_category_id', 'master' => 'vehicle-categories', 'label' => 'Vehicle type'],
                'fields' => ['name' => ['required', 'string', 'max:120']],
            ],
            'packages' => [
                'model' => Package::class,
                'label' => 'Packages',
                'singular' => 'Package',
                'group' => 'Price list',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    // Longer than it looks: these carry a full list of what the
                    // package includes, and v1's were several thousand
                    // characters of pasted markup.
                    'description' => ['nullable', 'string', 'max:20000'],
                    'price_paise' => ['required', 'integer', 'min:0', 'max:10000000'],
                ],
                'money' => ['price_paise'],
                // Edited with formatting, cleaned to a whitelist on save, and
                // never shown as raw markup in a table.
                'rich' => ['description'],
            ],
            'service-types' => [
                'model' => ServiceType::class,
                'label' => 'Interior cleaning',
                'singular' => 'Interior cleaning option',
                'group' => 'Price list',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'price_paise' => ['required', 'integer', 'min:0', 'max:10000000'],
                ],
                'money' => ['price_paise'],
            ],
            'durations' => [
                'model' => Duration::class,
                'label' => 'Durations',
                'singular' => 'Duration',
                'group' => 'Price list',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'months' => ['required', 'integer', 'min:1', 'max:60'],
                    // A flat discount off the whole term, not per month.
                    'discount_paise' => ['required', 'integer', 'min:0', 'max:10000000'],
                ],
                'money' => ['discount_paise'],
            ],
            'cloth-bundles' => [
                'model' => ClothBundle::class,
                'label' => 'Cloth bundles',
                'singular' => 'Cloth bundle',
                'group' => 'Price list',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'cloth_count' => ['required', 'integer', 'min:1', 'max:10000'],
                    'price_paise' => ['required', 'integer', 'min:0', 'max:10000000'],
                ],
                'money' => ['price_paise'],
            ],

            // ---- what the public site says ----
            'banners' => [
                'model' => Banner::class,
                'label' => 'Home banners',
                'singular' => 'Banner',
                'group' => 'Website',
                // The headline is the name here: a banner has no separate one,
                // and inventing a second field to label it would be busywork.
                'titleField' => 'headline',
                // Offered with a file picker rather than a path to type: a
                // path only works if somebody has already put the file on the
                // server by other means.
                'images' => ['image_path'],
                'fields' => [
                    'eyebrow' => ['nullable', 'string', 'max:120'],
                    'headline' => ['required', 'string', 'max:255'],
                    'subheadline' => ['nullable', 'string', 'max:500'],
                    'cta_label' => ['nullable', 'string', 'max:60'],
                    'cta_route' => ['nullable', 'string', 'max:60'],
                    'secondary_label' => ['nullable', 'string', 'max:60'],
                    'secondary_route' => ['nullable', 'string', 'max:60'],
                    // A path under /images, not a remote URL: a banner pointing
                    // at somebody else's server breaks without notice.
                    'image_path' => ['nullable', 'string', 'max:255'],
                    // Optional dates, so a festival offer takes itself down.
                    'starts_at' => ['nullable', 'date'],
                    'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
                    'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
                ],
                'long' => ['subheadline'],
                'dates' => ['starts_at', 'ends_at'],
                // A banner has ten fields, and a table showing all of them is
                // unreadable however wide the screen. These are the ones worth
                // scanning; the rest are edited in the form.
                'columns' => ['headline', 'cta_label', 'starts_at', 'ends_at', 'sort_order'],
            ],

            'post-categories' => [
                'model' => PostCategory::class,
                'label' => 'Blog categories',
                'singular' => 'Category',
                'group' => 'Website',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'description' => ['nullable', 'string', 'max:500'],
                    'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
                ],
                'long' => ['description'],
            ],

            'post-tags' => [
                'model' => PostTag::class,
                'label' => 'Blog tags',
                'singular' => 'Tag',
                'group' => 'Website',
                'fields' => ['name' => ['required', 'string', 'max:60']],
            ],

            'team' => [
                'model' => TeamMember::class,
                'label' => 'Team',
                'singular' => 'Team member',
                'group' => 'Website',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'title' => ['nullable', 'string', 'max:120'],
                    'bio' => ['nullable', 'string', 'max:2000'],
                    'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
                ],
                'long' => ['bio'],
            ],

            'message-templates' => [
                'model' => MessageTemplate::class,
                'label' => 'Message wording',
                'singular' => 'Message template',
                'group' => 'Website',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'description' => ['nullable', 'string', 'max:255'],
                    // The body a person edits. The key is not here on purpose:
                    // renaming it silently stops the job that sends it.
                    'body' => ['required', 'string', 'max:2000'],
                    'provider_template' => ['nullable', 'string', 'max:80'],
                    'bulk_sendable' => ['sometimes', 'boolean'],
                ],
                'long' => ['body', 'description'],
                'columns' => ['body', 'bulk_sendable'],
            ],

            'faqs' => [
                'model' => Faq::class,
                'label' => 'Questions',
                'singular' => 'Question',
                'group' => 'Website',
                'titleField' => 'question',
                'fields' => [
                    'question' => ['required', 'string', 'max:300'],
                    'answer' => ['required', 'string', 'max:5000'],
                    'category' => ['nullable', 'string', 'max:60'],
                    'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
                ],
                'long' => ['answer'],
                'columns' => ['question', 'category', 'sort_order'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $name): array
    {
        $all = self::all();

        if (! isset($all[$name])) {
            // A route that could name any table would be a way to edit anything
            // in the database through one endpoint.
            throw new InvalidArgumentException("There is no master called {$name}.");
        }

        return $all[$name] + ['key' => $name];
    }

    public static function exists(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /**
     * The list a settings screen renders its menu from.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function index(): array
    {
        $out = [];

        foreach (self::all() as $key => $definition) {
            $out[] = [
                'key' => $key,
                'label' => $definition['label'],
                'singular' => $definition['singular'],
                'group' => $definition['group'],
                'parent' => $definition['parent'] ?? null,
                'money' => $definition['money'] ?? [],
                // Which fields earn a table column. Empty means "all of them",
                // which is right for a master with three fields and wrong for
                // one with ten.
                'columns' => $definition['columns'] ?? [],
                // How each field should be edited and shown. Sent to the screen
                // so one generic form can render eleven different masters
                // without a special case per master in the front end.
                'rich' => $definition['rich'] ?? [],
                'long' => $definition['long'] ?? [],
                'dates' => $definition['dates'] ?? [],
                // Fields that hold a picture, so the form offers a file picker
                // rather than a box to type a path into.
                'images' => $definition['images'] ?? [],
                // Whether this master is assigned to people, so the form knows
                // to render the picker.
                'staff' => $definition['staff'] ?? false,
                'title_field' => $definition['titleField'] ?? 'name',
                'fields' => array_keys($definition['fields']),
            ];
        }

        return $out;
    }
}
