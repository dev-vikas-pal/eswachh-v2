<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Session based sign in for the SPA.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        /*
         * Cookie authentication needs a session, and Sanctum only starts one
         * for a request from a configured stateful domain. If the domain is
         * missing from SANCTUM_STATEFUL_DOMAINS the session is absent and
         * everything below fails with an unhelpful error, so say what is
         * actually wrong instead.
         */
        if (! $request->hasSession()) {
            report(new \RuntimeException(
                'Login was attempted from a non-stateful origin: '.($request->headers->get('Origin') ?? 'none').
                '. Add it to SANCTUM_STATEFUL_DOMAINS.'
            ));

            return response()->json([
                'message' => 'This site is not configured to sign in from this address.',
            ], 503);
        }

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        // Throttled per address and per email together, so one attacker cannot
        // lock out a real customer by guessing at their address.
        $key = 'login:'.$request->ip().'|'.$credentials['email'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false)
        )) {
            RateLimiter::hit($key, 60);

            // One message for both wrong email and wrong password: telling them
            // apart tells an attacker which addresses exist.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match our records.',
            ]);
        }

        $user = Auth::user();

        if (! $user->status) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been disabled.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return response()->json([
            'data' => new UserResource($user->load('branch')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }
}
