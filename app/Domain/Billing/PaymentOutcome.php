<?php

namespace App\Domain\Billing;

use App\Models\Payment;

/**
 * What happened when a callback was processed.
 *
 * Returned rather than thrown, because "we already handled this" and "that
 * signature is wrong" are both ordinary outcomes that the caller must tell
 * apart and show differently. v1 returned a bare redirect from every branch,
 * so a forged callback and a real one looked identical to the customer.
 */
class PaymentOutcome
{
    private function __construct(
        public readonly string $result,
        public readonly ?Payment $payment = null,
        public readonly ?string $message = null,
    ) {}

    public static function captured(Payment $payment): self
    {
        return new self('captured', $payment, 'Payment received. Thank you.');
    }

    /** A repeat of a callback we have already banked. Safe, and not an error. */
    public static function alreadyHandled(?Payment $payment): self
    {
        return new self('already_handled', $payment, 'This payment has already been recorded.');
    }

    /**
     * The money is ours but the subscription did not move. Someone has to look
     * at it, which is why it is a distinct outcome rather than a silent success.
     */
    public static function capturedButIncomplete(Payment $payment): self
    {
        return new self(
            'captured_incomplete',
            $payment,
            'Payment received. Your plan will be updated shortly.'
        );
    }

    public static function rejected(string $message): self
    {
        return new self('rejected', null, $message);
    }

    public function succeeded(): bool
    {
        return in_array($this->result, ['captured', 'already_handled', 'captured_incomplete'], true);
    }

    /** True when a person needs to intervene. */
    public function needsAttention(): bool
    {
        return $this->result === 'captured_incomplete';
    }
}
