import { reactive, readonly } from 'vue';

/**
 * Where a payment has got to, for the whole application at once.
 *
 * Kept here rather than on each page because there are seven places that take
 * money - the public signup, the public renewal, the public cloth top-up, the
 * customer's renew, top-up and add-a-car, and the office taking a payment over
 * the phone - and a person mid-payment on any of them needs the same thing:
 * to be told to sit still, and to be told when it is safe to stop.
 *
 * Every one of those goes through `completeCheckout`, so setting it there means
 * a new payment screen cannot be written that forgets to do this.
 */
export type PaymentPhase =
    /** Nothing happening. */
    | 'idle'
    /** Asking our server to open the payment, before any gateway is involved. */
    | 'opening'
    /**
     * The gateway's own window is up.
     *
     * Deliberately *not* covered by our overlay: Razorpay draws its own dialog
     * and putting a sheet over it would block the very thing the customer is
     * being asked to use.
     */
    | 'gateway'
    /**
     * The dangerous one. The gateway has taken the money and we are verifying
     * the signature and writing it down. A refresh here leaves a customer
     * charged and looking at a page that never told them so - the reconciler
     * would sort it out overnight, but nobody wants to find out that way.
     */
    | 'confirming';

const state = reactive<{ phase: PaymentPhase }>({ phase: 'idle' });

/**
 * Should the screen be held while this phase runs?
 *
 * The gateway phase is the exception and the reason this is a phase rather
 * than a boolean.
 */
export function blocksTheScreen(phase: PaymentPhase): boolean {
    return phase === 'opening' || phase === 'confirming';
}

/**
 * A refresh while money is in flight is the one thing that actually loses
 * information, so the browser is asked to check. Browsers show their own
 * wording and ignore ours, which is fine - the point is the prompt, not the
 * sentence.
 */
function warnBeforeLeaving(event: BeforeUnloadEvent) {
    event.preventDefault();
    event.returnValue = '';
}

export const paymentProgress = readonly(state);

export function setPaymentPhase(phase: PaymentPhase): void {
    state.phase = phase;

    if (phase === 'confirming') {
        window.addEventListener('beforeunload', warnBeforeLeaving);
    } else {
        window.removeEventListener('beforeunload', warnBeforeLeaving);
    }
}
