<?php

namespace App\Domain\Auth;

use App\Domain\Messaging\Messenger;
use App\Models\LoginCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One time codes sent to a mobile number.
 *
 * Two things use these and they must behave identically: signing in, and
 * proving the number on a signup form belongs to whoever is filling it in. v1
 * had the same idea and one implementation with a hole in it - the code 112233
 * was accepted for any number, in any environment, forever - so it is written
 * once here rather than twice.
 *
 * The purpose is part of the lookup. A code issued to sign in must not be
 * usable to register somebody else's number, and the other way round.
 */
class PhoneCodes
{
    public const LOGIN = 'login';

    public const SIGNUP = 'signup';

    /** Long enough to read a message, not long enough to be worth stealing. */
    private const LIFETIME_MINUTES = 5;

    /**
     * Issue a code and send it.
     *
     * Anything outstanding for this number and purpose is spent first, so two
     * live codes never exist at once.
     */
    public function issue(string $phone, string $purpose, ?string $ip = null): LoginCode
    {
        LoginCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);

        $record = LoginCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            // Hashed like a password: a leaked backup of this table must not
            // hand anybody a working code.
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            'requested_ip' => $ip,
        ]);

        $this->deliver($phone, $code);

        return $record;
    }

    /**
     * Check a code and spend it.
     *
     * Returns false for every kind of failure - wrong, expired, already used,
     * guessed at too often - because the caller must say the same thing for all
     * of them. Distinguishing them tells an attacker which half to work on.
     */
    public function consume(string $phone, string $code, string $purpose): bool
    {
        $record = $this->check($phone, $code, $purpose);

        if (! $record) {
            return false;
        }

        $this->spend($record);

        return true;
    }

    /**
     * Check a code without spending it.
     *
     * For callers that have more to validate before they commit. Signup used
     * `consume()` first and checked the car number afterwards, so a plate that
     * was already taken burned the code as well: the customer corrected the
     * plate, retyped the same code, and was told it was invalid. Nothing they
     * could see explained why, and asking for a new one only did it again.
     *
     * A wrong guess still counts against the attempt limit, because that is
     * what stops six digits being walked through. Only success is free.
     */
    public function check(string $phone, string $code, string $purpose): ?LoginCode
    {
        $record = LoginCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (! $record || ! $record->isUsable()) {
            return null;
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            // Burned once the guesses run out. Six digits is only strong while
            // the number of attempts is small, and v1 never counted them.
            if ($record->attempts >= LoginCode::MAX_ATTEMPTS) {
                $this->spend($record);
            }

            return null;
        }

        return $record;
    }

    /**
     * Mark a checked code as used.
     *
     * Once spent, a code read twice - from a shared phone, from a screenshot -
     * works once.
     */
    public function spend(LoginCode $record): void
    {
        $record->forceFill(['consumed_at' => now()])->save();
    }

    public function lifetimeSeconds(): int
    {
        return self::LIFETIME_MINUTES * 60;
    }

    /**
     * Digits only, last ten kept.
     *
     * The same customer types 98765 43210, +91 98765 43210 and 09876543210 on
     * three different days and means one phone each time.
     */
    public static function normalise(string $phone): string
    {
        return substr((string) preg_replace('/\D+/', '', $phone), -10);
    }

    /**
     * Send it - or, everywhere but production, write it down instead.
     *
     * The environment check cannot be turned off by a stray .env line. A
     * developer running against a copy of live data must not text real people.
     */
    private function deliver(string $phone, string $code): void
    {
        if (! app()->isProduction() || app()->runningUnitTests()) {
            Log::info('Phone code (not sent: '.app()->environment().')', [
                // As it would actually be addressed, country code and all, so
                // the log shows what would really have gone out rather than the
                // ten digits we happen to store.
                'phone' => Messenger::dialable($phone),
                'code' => $code,
            ]);

            return;
        }

        /*
         * Deliberately swallowed on failure. A code that could not be sent must
         * not return a 500, because a 500 for one number and a 200 for another
         * is itself an answer to "is this person a customer of yours".
         */
        try {
            Http::withHeaders(['authkey' => (string) config('services.whatsapp.key')])
                ->acceptJson()
                ->timeout(15)
                ->post(config('services.whatsapp.url'), [
                    'integrated_number' => config('services.whatsapp.number'),
                    'content_type' => 'template',
                    'payload' => [
                        // With the country code. A bare ten digit number is
                        // accepted and dropped, which looks like a code that
                        // was sent and never arrives.
                        'to' => Messenger::dialable($phone),
                        'type' => 'template',
                        'template' => [
                            'name' => 'otp',
                            'language' => ['code' => 'en', 'policy' => 'deterministic'],
                            'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]]],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('A phone code could not be sent.', ['error' => $e->getMessage()]);
        }
    }
}
