import { ref } from 'vue';
import { api, describeError } from '@/shared/api/client';
import { payForLinkedPlan, type PaymentReceipt } from '@/shared/api/checkout';
import type { RenewablePlan } from '@/site/RenewPlanCard.vue';

/**
 * Renewing from the Renew now button in a WhatsApp message.
 *
 * The whole point is that there is nothing to do on arrival: the token in the
 * address says which plan this is, so the page opens with the car, the price
 * and a button, and one tap finishes it. A customer who has been asked three
 * times to renew should not then be asked to type their registration.
 *
 * The token is the only credential, which is why it never leaves this module
 * for anything but the two calls below - and why the pay call sends the token
 * again rather than the subscription id the lookup returned.
 */

export function useRenewLink(token: string) {
    const loading = ref(true);
    const paying = ref(false);
    const plan = ref<RenewablePlan | null>(null);

    /**
     * The link did not resolve to anything payable.
     *
     * Kept apart from `problem` below. This one replaces the page - there is
     * nothing here to try again - and always points at the car-number page,
     * which can still help somebody whose link has gone stale.
     */
    const dead = ref<string | null>(null);

    /** A failure that belongs beside the button that failed. */
    const problem = ref<string | null>(null);

    const paid = ref<{ receipt?: PaymentReceipt; car: string; renewed: RenewablePlan } | null>(null);

    async function load() {
        loading.value = true;
        dead.value = null;

        try {
            const { data } = await api.post('/public/renew/link', { token });
            plan.value = data.data;
        } catch (error) {
            dead.value = describeError(error).message;
        } finally {
            loading.value = false;
        }
    }

    async function pay() {
        if (!plan.value) return;

        const renewing = plan.value;

        paying.value = true;
        problem.value = null;

        const result = await payForLinkedPlan(token);
        paying.value = false;

        if (result.ok) {
            paid.value = { receipt: result.payment, car: renewing.registration, renewed: renewing };
            plan.value = null;
            return;
        }

        problem.value = result.message;
    }

    return { loading, paying, plan, dead, problem, paid, load, pay };
}
