<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Banner;
use App\Models\City;
use App\Models\ClothBundle;
use App\Models\Duration;
use App\Models\Faq;
use App\Models\Package;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Society;
use App\Models\State;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Content\RichText;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the public signup form needs to draw itself.
 *
 * Unauthenticated, so it is the most exposed surface in the system and is
 * deliberately narrow: it returns names and prices of things that are on sale,
 * and nothing else. No counts, no customers, no branch names - a competitor
 * reading this should learn only what a price list already tells them.
 *
 * Withdrawn and switched-off rows never appear here, however they may still be
 * referenced by existing plans.
 */
class CatalogueController extends Controller
{
    /**
     * Everything needed to price a plan, in one call.
     *
     * One request rather than five, because the form needs all of it before it
     * can show anything, and five round trips on a phone is a visible wait.
     */
    public function __invoke(): JsonResponse
    {
        return BranchContext::withoutScope(fn () => response()->json([
            'data' => [
                'vehicle_types' => VehicleCategory::query()->where('status', true)->orderBy('name')
                    ->get(['id', 'name', 'price_paise'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'price' => $row->price_paise / 100,
                    ]),

                'car_models' => VehicleModel::query()->where('status', true)->orderBy('name')
                    ->get(['id', 'name', 'vehicle_category_id'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'vehicle_type_id' => $row->vehicle_category_id,
                    ]),

                'packages' => Package::query()->where('status', true)->orderBy('price_paise')
                    ->get(['id', 'name', 'description', 'price_paise'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        // Taken apart into text and bullets rather than sent as
                        // markup. v1 stored whatever a WYSIWYG editor produced,
                        // and rendering that would hand anyone who can edit a
                        // package the ability to run script on the public site.
                        'summary' => RichText::summary($row->description),
                        'sections' => RichText::sections($row->description),
                        'price' => $row->price_paise / 100,
                    ]),

                'service_types' => ServiceType::query()->where('status', true)->orderBy('price_paise')
                    ->get(['id', 'name', 'price_paise'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'price' => $row->price_paise / 100,
                    ]),

                'durations' => Duration::query()->where('status', true)->orderBy('months')
                    ->get(['id', 'name', 'months', 'discount_paise'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'months' => $row->months,
                        'discount' => $row->discount_paise / 100,
                    ]),

                'cloth_bundles' => ClothBundle::query()->where('status', true)->orderBy('cloth_count')
                    ->get(['id', 'name', 'cloth_count', 'price_paise'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'count' => $row->cloth_count,
                        'price' => $row->price_paise / 100,
                    ]),
            ],
        ]));
    }

    /**
     * The words on the public site: banners and questions.
     *
     * Separate from the catalogue because it changes on a different rhythm -
     * a festival banner goes up for a fortnight, a price list rarely moves -
     * and the home page should not refetch the whole price list to show a
     * headline.
     */
    /**
     * A policy page.
     *
     * Kept out of `content` so the home page does not download twenty thousand
     * characters of terms to show a headline. A page nobody has written yet is
     * a 404 rather than an empty page with a heading on it.
     */
    public function policy(string $page): JsonResponse
    {
        $keys = ['privacy' => 'privacy_policy', 'terms' => 'terms', 'refunds' => 'refund_policy'];

        abort_unless(isset($keys[$page]), 404);

        $body = (string) SiteSettings::get($keys[$page]);

        // Only reachable if somebody has saved an empty one over the default.
        abort_if(trim($body) === '', 404);

        return response()->json([
            'data' => [
                'title' => ['privacy' => 'Privacy policy', 'terms' => 'Terms of service', 'refunds' => 'Cancellation and refunds'][$page],
                // Markup, cleaned when it was saved rather than now - see
                // HtmlSanitizer. No attribute survives that pass, so there is
                // nothing here for a script to hang off.
                'body' => $body,
                'business_name' => SiteSettings::get('business_name'),
                /*
                 * When this page last changed. Null while it is still the
                 * wording we shipped, which is honest: nobody at the business
                 * has agreed to a date on it yet.
                 */
                'updated_at' => DB::table('site_settings')
                    ->where('key', $keys[$page])
                    ->value('updated_at'),
            ],
        ]);
    }

    public function content(): JsonResponse
    {
        return response()->json([
            'data' => [
                'banners' => Banner::query()->live()->get()->map(fn (Banner $b) => [
                    'id' => $b->id,
                    'eyebrow' => $b->eyebrow,
                    'headline' => $b->headline,
                    'subheadline' => $b->subheadline,
                    'cta' => $b->cta_label ? ['label' => $b->cta_label, 'route' => $b->cta_route] : null,
                    'secondary' => $b->secondary_label
                        ? ['label' => $b->secondary_label, 'route' => $b->secondary_route]
                        : null,
                    'image' => $b->image_path,
                ]),

                /*
                 * Only the details meant for the public. The settings table
                 * also holds an invoice prefix and a grace period, and neither
                 * is anybody else's business.
                 */
                'contact' => [
                    'business_name' => SiteSettings::get('business_name'),
                    'address' => SiteSettings::get('address'),
                    'phone' => SiteSettings::get('contact_phone'),
                    'email' => SiteSettings::get('contact_email'),
                    'whatsapp' => SiteSettings::get('whatsapp_number'),
                    'hours' => SiteSettings::get('office_hours'),
                ],

                'faqs' => Faq::query()->live()->get()->map(fn (Faq $f) => [
                    'id' => $f->id,
                    'question' => $f->question,
                    'answer' => $f->answer,
                    'category' => $f->category,
                ]),
            ],
        ]);
    }

    /**
     * One level of the address, given the one above it.
     *
     * Cascading rather than sending every society in the country to a phone.
     * The parent is required: without it this would be a way to enumerate the
     * whole address book in one request.
     */
    public function locations(Request $request): JsonResponse
    {
        $input = $request->validate([
            'level' => ['required', 'string', 'in:states,cities,areas,sectors,societies'],
            'parent_id' => ['required_unless:level,states', 'nullable', 'string'],
        ]);

        return BranchContext::withoutScope(function () use ($input) {
            $rows = match ($input['level']) {
                'states' => State::query()->where('status', true)->orderBy('name')->get(['id', 'name']),

                'cities' => City::query()->where('status', true)
                    ->where('state_id', $input['parent_id'])->orderBy('name')->get(['id', 'name']),

                'areas' => Area::query()->where('status', true)
                    ->where('city_id', $input['parent_id'])->orderBy('name')->get(['id', 'name']),

                /*
                 * Only sectors a franchise actually covers. Offering an address
                 * nobody services takes a customer's money for a round that
                 * will never happen - which is how v1 accumulated subscriptions
                 * with no cleaner attached.
                 */
                'sectors' => Sector::query()->where('status', true)
                    ->whereNotNull('branch_id')
                    ->where('area_id', $input['parent_id'])->orderBy('name')->get(['id', 'name']),

                'societies' => Society::query()->where('status', true)
                    ->where('sector_id', $input['parent_id'])->orderBy('name')
                    ->get(['id', 'name', 'surcharge_paise'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        // Shown up front, so the surcharge is not a surprise at
                        // the payment step.
                        'surcharge' => $row->surcharge_paise / 100,
                    ]),
            };

            return response()->json(['data' => $rows]);
        });
    }
}
