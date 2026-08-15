<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\Log;

/**
 * Proves a payment callback really came from Razorpay.
 *
 * Razorpay signs `order_id|payment_id` with the account secret. Without this
 * check anyone who can POST to the callback can claim a payment succeeded.
 * v1 never verified it at all.
 *
 * Kept free of the SDK so it can be tested without a network or a real
 * account: it is a single HMAC comparison.
 */
class RazorpaySignature
{
    /**
     * @param  array<string, mixed>  $callback
     */
    public static function isValid(array $callback, ?string $secret = null): bool
    {
        $secret ??= (string) config('services.razorpay.secret');

        $orderId = $callback['razorpay_order_id'] ?? null;
        $paymentId = $callback['razorpay_payment_id'] ?? null;
        $signature = $callback['razorpay_signature'] ?? null;

        if (! $orderId || ! $paymentId || ! $signature || $secret === '') {
            Log::warning('Razorpay callback could not be verified.', [
                'has_order' => (bool) $orderId,
                'has_payment' => (bool) $paymentId,
                'has_signature' => (bool) $signature,
                'has_secret' => $secret !== '',
            ]);

            // Unlike v1, a callback that cannot be verified is rejected rather
            // than trusted. A missing signature is a failure, not a pass.
            return false;
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        // Constant time: a plain === leaks how much of the signature matched.
        return hash_equals($expected, (string) $signature);
    }

    /**
     * The signature Razorpay would send. Used by tests, never in the app.
     */
    public static function sign(string $orderId, string $paymentId, string $secret): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);
    }
}
