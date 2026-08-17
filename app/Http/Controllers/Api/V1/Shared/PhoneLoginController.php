<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Domain\Auth\PhoneCodes;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signing in with a code sent to a mobile number.
 *
 * This is how v1's customers signed in, and it has to stay: they were imported
 * with the bcrypt hashes of passwords they have never typed. Asking them for
 * one would lock out the entire customer base on the day this goes live.
 *
 * The code handling itself lives in PhoneCodes, shared with the signup form.
 * What is here is who may use it: customers only. Staff sign in with a password
 * on the office screen, because a code to a mobile is a weaker second door and
 * it should not be one that opens onto a whole branch's data.
 */
class PhoneLoginController extends Controller
{
    public function __construct(private PhoneCodes $codes) {}

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        $phone = PhoneCodes::normalise($data['phone']);

        $this->throttle($request, $phone);

        /*
         * Said plainly, at the owner's request.
         *
         * This was deliberately vague - "if that number is on our books" - so
         * the form could not be used to ask whether somebody is a customer. The
         * reason it can be plain now is that the *signup* form already answers
         * the same question outright: it refuses a number it knows with "that
         * number is already registered". The information was public either way,
         * and the vague wording only cost a real customer their bearings when a
         * code never arrived because they had mistyped a digit.
         *
         * Still throttled per number and per address, which is what actually
         * stops a list being walked through.
         */
        if (! $this->userFor($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'We have no account for that number. Check the digits, or subscribe to start one.',
            ]);
        }

        $this->codes->issue($phone, PhoneCodes::LOGIN, $request->ip());

        return response()->json([
            'message' => 'A code is on its way. It lasts five minutes.',
            'expires_in' => $this->codes->lifetimeSeconds(),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'This site is not configured to sign in from this address.',
            ], 503);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $phone = PhoneCodes::normalise($data['phone']);

        // One message for every kind of failure: wrong, expired, already used,
        // guessed at too often. Telling them apart tells an attacker which half
        // of the problem to work on.
        $refuse = fn () => ValidationException::withMessages([
            'code' => 'That code is not valid. Ask for a new one.',
        ]);

        if (! $this->codes->consume($phone, $data['code'], PhoneCodes::LOGIN)) {
            throw $refuse();
        }

        $user = $this->userFor($phone);

        if (! $user) {
            // The account went away between asking and answering.
            throw $refuse();
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        RateLimiter::clear('otp:phone:'.$phone);

        return response()->json(['data' => new UserResource($user->load('branch'))]);
    }

    // --------------------------------------------------------------- private

    /**
     * Per number and per address together.
     *
     * One person cannot be locked out by somebody hammering their number from
     * elsewhere, and one address cannot walk the phone book.
     */
    private function throttle(Request $request, string $phone): void
    {
        /*
         * Five per number, not three. Asking again is a supported thing to do -
         * there is a Send it again button - and a message that arrives slowly
         * is exactly when somebody presses it twice. Three left an honest
         * person locked out for ten minutes.
         */
        foreach (['otp:phone:'.$phone => 5, 'otp:ip:'.$request->ip() => 15] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages([
                    'phone' => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.',
                ])->status(429);
            }

            RateLimiter::hit($key, 600);
        }
    }

    private function userFor(string $phone): ?User
    {
        return User::query()
            ->where('role', UserRole::Customer)
            ->where('status', true)
            /*
             * The same ten digits, written the four ways this data actually
             * holds them. Matched as equalities rather than with a function
             * around the column, because a function there cannot use the index
             * and this runs on every sign in attempt.
             */
            ->where(fn ($q) => $q->where('phone', $phone)
                ->orWhere('phone', '0'.$phone)
                ->orWhere('phone', '91'.$phone)
                ->orWhere('phone', '+91'.$phone))
            ->first();
    }
}
