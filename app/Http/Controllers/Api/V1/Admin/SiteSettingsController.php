<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Business details.
 *
 * Administrator only: these are one set of values for the whole business, not
 * per branch, so a franchise owner changing the GSTIN would change it for
 * everybody.
 */
class SiteSettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    public function show(): JsonResponse
    {
        $values = SiteSettings::all();

        $groups = [];

        foreach (SiteSettings::definitions() as $key => $definition) {
            $groups[$definition['group']][] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $values[$key] ?? '',
                'long' => (bool) ($definition['long'] ?? false),
                // A policy page needs headings and lists to be readable; a
                // GSTIN does not. The form picks its control from this rather
                // than from the field's name.
                'rich' => (bool) ($definition['rich'] ?? false),
            ];
        }

        return response()->json([
            'data' => collect($groups)->map(fn ($fields, $name) => [
                'group' => $name,
                'fields' => $fields,
            ])->values(),
            // Said on the screen rather than left to be discovered: somebody
            // will otherwise look here for the Razorpay key.
            'note' => 'Payment and messaging keys are configured on the server, not here - '
                .'they must not appear in a database backup.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->validate(SiteSettings::rules());

        SiteSettings::put($values);

        return response()->json([
            'message' => 'Saved.',
            'data' => SiteSettings::all(),
        ]);
    }
}
