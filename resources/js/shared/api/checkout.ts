import { api, describeError } from '@/shared/api/client';
import { setPaymentPhase } from '@/shared/paymentProgress';

/**
 * Taking a payment in the browser.
 *
 * Three steps, and the order matters: we open the payment on the server first,
 * so a customer who abandons checkout still leaves a record to chase. Only then
 * does Razorpay's dialog open. What comes back from it is posted to our
 * callback, which verifies the signature before believing any of it.
 *
 * Nothing here decides an amount. The server priced the plan when the payment
 * was opened, and prices it again when the callback lands.
 */

export interface Checkout {
    payment_id: string;
    order_id: string;
    amount_paise: number;
    currency: string;
    gateway_key: string | null;
    /** No gateway configured: the dialog is skipped and the server stands in. */
    simulated: boolean;
}

export interface PaymentResult {
    ok: boolean;
    message: string;
    /** True when the customer closed the dialog rather than anything failing. */
    cancelled?: boolean;
    /**
     * The captured payment, when there is one.
     *
     * Carried back so the page that follows can show a receipt - an invoice
     * number and what was paid - rather than a sentence saying it worked.
     */
    payment?: PaymentReceipt;
}

export interface PaymentReceipt {
    invoice_number: string | null;
    amount: number;
    method: string | null;
    paid_at: string | null;
}

declare global {
    interface Window {
        Razorpay?: new (options: Record<string, unknown>) => { open: () => void };
    }
}

const CHECKOUT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

/** Loaded on demand, not on every page: most visits never pay for anything. */
function loadRazorpay(): Promise<void> {
    if (window.Razorpay) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(`script[src="${CHECKOUT_SRC}"]`);

        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('load failed')));
            return;
        }

        const script = document.createElement('script');
        script.src = CHECKOUT_SRC;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('load failed'));
        document.head.appendChild(script);
    });
}

/**
 * Ask our server to open a payment, then hand off to the gateway.
 *
 * Every flow below is the same two steps and differs only in what it posts, so
 * they share one of these. That matters beyond tidiness: the phase the progress
 * sheet reads is set here, and a flow written without it would take somebody's
 * money behind an unmarked screen.
 */
export async function openThenPay(
    open: () => Promise<{ data: { data: Checkout } }>,
    customer: { name?: string; email?: string; phone?: string } = {},
): Promise<PaymentResult> {
    setPaymentPhase('opening');

    let checkout: Checkout;

    try {
        const { data } = await open();
        checkout = data.data;
    } catch (error) {
        setPaymentPhase('idle');

        return { ok: false, message: describeError(error).message };
    }

    return completeCheckout(checkout, customer);
}

/**
 * @param subscriptionId  What is being paid for
 * @param customer        Prefilled into the dialog, so nobody retypes it
 */
export async function payForSubscription(
    subscriptionId: string,
    customer: { name?: string; email?: string; phone?: string } = {},
): Promise<PaymentResult> {
    return openThenPay(() => api.post(`/subscriptions/${subscriptionId}/pay`), customer);
}

/**
 * The receipt out of whatever the server sent back.
 *
 * Both paths - the simulated one and the real callback - return the payment
 * resource, but neither is guaranteed to: a response shape that changes must
 * degrade to "no receipt", never to a page that throws after the money has
 * been taken.
 */
function receiptFrom(data: { data?: Record<string, unknown> }): PaymentReceipt | undefined {
    const payment = data?.data;

    if (!payment) return undefined;

    return {
        invoice_number: (payment.invoice_number as string) ?? null,
        amount: Number(payment.amount ?? 0),
        method: (payment.method as string) ?? null,
        paid_at: (payment.paid_at as string) ?? null,
    };
}

/**
 * The gateway half: simulated stand-in, or the real dialog.
 *
 * Shared by every flow that takes money, so there is one place that decides
 * what to do with what the dialog returns.
 */
export async function completeCheckout(
    checkout: Checkout,
    customer: { name?: string; email?: string; phone?: string },
): Promise<PaymentResult> {
    if (checkout.simulated) {
        // No gateway on this machine. The server refuses this the moment a
        // real gateway is configured, so it cannot leak into production.
        setPaymentPhase('confirming');

        try {
            const { data } = await api.post(`/payments/${checkout.payment_id}/simulate`);
            return { ok: true, message: data.message ?? 'Payment recorded.', payment: receiptFrom(data) };
        } catch (error) {
            return { ok: false, message: describeError(error).message };
        } finally {
            setPaymentPhase('idle');
        }
    }

    try {
        await loadRazorpay();
    } catch {
        setPaymentPhase('idle');

        return {
            ok: false,
            message: 'The payment window could not be opened. Check your connection and try again.',
        };
    }

    /*
     * The gateway draws its own dialog, so our sheet comes down for as long as
     * it is up. Covering it would block the very thing the customer has been
     * asked to use.
     */
    setPaymentPhase('gateway');

    return new Promise<PaymentResult>((resolve) => {
        const razorpay = new window.Razorpay!({
            key: checkout.gateway_key,
            order_id: checkout.order_id,
            amount: checkout.amount_paise,
            currency: checkout.currency,
            name: 'Eswachh',
            description: 'Car cleaning subscription',
            prefill: {
                name: customer.name ?? '',
                email: customer.email ?? '',
                contact: customer.phone ?? '',
            },
            theme: { color: '#EA580C' },

            handler: async (response: Record<string, string>) => {
                /*
                 * The money has been taken. Everything from here to the reply
                 * below is the window where a refresh leaves a customer charged
                 * and looking at a page that never said so, which is what the
                 * sheet is there to prevent.
                 */
                setPaymentPhase('confirming');

                /*
                 * What the dialog returns is not proof of anything. It is
                 * posted to our callback, which checks the signature and asks
                 * the gateway what it actually holds before recording a rupee.
                 */
                try {
                    const { data } = await api.post('/payments/callback', {
                        razorpay_order_id: response.razorpay_order_id,
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_signature: response.razorpay_signature,
                    });

                    resolve({ ok: true, message: data.message ?? 'Payment received.', payment: receiptFrom(data) });
                } catch (error) {
                    // The money may well have been taken even though this
                    // failed, so the wording never says the payment failed -
                    // reconciliation will settle it overnight either way.
                    resolve({
                        ok: false,
                        message:
                            describeError(error).message
                            + ' If money has left your account it will be applied automatically.',
                    });
                } finally {
                    setPaymentPhase('idle');
                }
            },

            modal: {
                ondismiss: () => {
                    setPaymentPhase('idle');

                    resolve({
                        ok: false,
                        cancelled: true,
                        message: 'Payment cancelled. Nothing has been charged.',
                    });
                },
            },
        });

        razorpay.open();
    });
}

/**
 * Renew a running plan.
 *
 * Same checkout as everything else, deliberately: the simulated stand-in, the
 * signature check and the wording used when a callback fails all have to behave
 * identically whoever started the payment. The renewal price is the server's -
 * nothing is sent from here but the plan's id.
 */
export async function payForRenewal(
    subscriptionId: string,
    customer: { name?: string; email?: string; phone?: string } = {},
    /**
     * What they want to renew onto, when it differs from what they have.
     *
     * Ids only. The server applies them and prices the result - so a customer
     * choosing six months instead of one is charged for six months, worked out
     * by the price book rather than sent from here.
     */
    choices: Record<string, string | null> = {},
): Promise<PaymentResult> {
    return openThenPay(() => api.post(`/subscriptions/${subscriptionId}/renew`, choices), customer);
}

/** Buy another bundle of cloths for a running plan. */
export async function payForClothTopUp(
    subscriptionId: string,
    clothBundleId: string,
    customer: { name?: string; email?: string; phone?: string } = {},
): Promise<PaymentResult> {
    return openThenPay(
        () => api.post(`/subscriptions/${subscriptionId}/top-up`, { cloth_bundle_id: clothBundleId }),
        customer,
    );
}

/**
 * Add a second car to an account that already exists.
 *
 * No code step and no address: the customer is signed in, which proves more
 * than a code sent to a phone does, and everything about who they are comes
 * from the session. Only the car and what they want on it are sent.
 */
export async function payForNewPlan(
    plan: Record<string, string | null>,
    customer: { name?: string; email?: string; phone?: string } = {},
): Promise<PaymentResult> {
    return openThenPay(() => api.post('/portal/plans', plan), customer);
}

/**
 * Top up cloths for a car found by number, with nobody signed in.
 *
 * The registration goes with the id, as it does on the renewal page: an id
 * alone would be enough to top up somebody else's car, and ids leak far more
 * easily than the pairing of the two does.
 */
export async function payForClothTopUpByCar(
    subscriptionId: string,
    registration: string,
    clothBundleId: string,
): Promise<PaymentResult> {
    return openThenPay(() => api.post('/public/cloth/pay', {
        subscription_id: subscriptionId,
        registration,
        cloth_bundle_id: clothBundleId,
    }));
}

/**
 * Pay for a plan found by car number, with nobody signed in.
 *
 * The registration is sent again with the id: without it, an id alone would be
 * enough to open a payment for any plan, and ids leak far more easily than the
 * pairing of the two does.
 */
export async function payForFoundPlan(
    subscriptionId: string,
    registration: string,
): Promise<PaymentResult> {
    return openThenPay(() => api.post('/public/renew/pay', {
        subscription_id: subscriptionId,
        registration,
    }));
}
