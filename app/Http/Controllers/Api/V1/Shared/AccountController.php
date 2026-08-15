<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Your own account, as opposed to somebody else's.
 *
 * v1 had this and v2 did not: an administrator could set anybody's password
 * from the staff screen, but nobody could change their own without asking an
 * administrator to do it - which means a password only somebody else has ever
 * chosen.
 *
 * There is no user id in this route on purpose. The one being changed is the
 * one signed in, so there is nothing to point at somebody else.
 */
class AccountController extends Controller
{
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        // Throttled per account. The current password is being guessed at here
        // just as it would be on a login form, and a signed in session is not a
        // reason to stop counting.
        $key = 'password-change:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'current_password' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            // Laravel's own rules, including the check against known breached
            // passwords. A short minimum was v1's only requirement.
            'password' => ['required', 'confirmed', Password::min(8)->uncompromised()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        RateLimiter::clear($key);

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        /*
         * Every other session for this account is ended, and this one is kept.
         * Changing a password is what somebody does when they think another
         * person has it, and leaving that person signed in elsewhere makes the
         * change pointless.
         */
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
