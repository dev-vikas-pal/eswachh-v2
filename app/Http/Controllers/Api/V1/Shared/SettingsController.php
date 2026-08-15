<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Support\Preferences\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed in person's own interface settings.
 *
 * No authorisation beyond being signed in, and no user id in the route: these
 * are always your own. An administrator has no business setting somebody
 * else's theme, so there is no way to address another user here at all.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->settings(),
            // The choices come from the server so a settings screen does not
            // hard-code a list that can drift out of step with what is allowed.
            'options' => UserPreferences::options(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Every setting is optional: changing the theme should not require
        // sending back the menu position you did not touch.
        $changes = $request->validate(UserPreferences::rules());

        return response()->json([
            'data' => $request->user()->updateSettings($changes),
        ]);
    }
}
