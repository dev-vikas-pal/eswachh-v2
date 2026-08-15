import { ref, type Ref } from 'vue';
import { api, describeError, type ValidationErrors } from '@/shared/api/client';
import { completeCheckout, type Checkout } from '@/shared/api/checkout';
import { useCodeCooldown } from '@/shared/useCodeCooldown';

/**
 * Placing an order from the public site.
 *
 * Kept out of the view because it is the only unauthenticated write in the
 * system and it deserves reading on its own: prove the number, place the order
 * by id, then pay. Nothing here sends a price - the figure in the panel is a
 * quote the server produced, and the server prices the order again from the
 * same ids when it is placed. v1 posted its own total and was charged it.
 */

export interface SignupForm {
    name: string;
    email: string;
    phone: string;
    registration: string;
    state_id: string;
    city_id: string;
    area_id: string;
    sector_id: string;
    society_id: string;
    house_no: string;
    preferred_time: string;
    vehicle_model_id: string;
    package_id: string;
    service_type_id: string;
    duration_id: string;
    cloth_bundle_id: string;
}

/** Where the form has got to. The view renders one of these three. */
export type SignupStage = 'details' | 'code' | 'done';

export function useSignup(form: Ref<SignupForm>) {
    const stage = ref<SignupStage>('details');
    const code = ref('');
    const busy = ref(false);
    const error = ref<string | null>(null);
    const notice = ref<string | null>(null);
    const fieldErrors = ref<ValidationErrors>({});

    /** How long before "send it again" is allowed. */
    const cooldown = useCodeCooldown();

    function fail(e: unknown) {
        const described = describeError(e);
        error.value = described.message;
        fieldErrors.value = described.errors;
    }

    /**
     * Step one: prove the mobile number.
     *
     * Before anything is created, so an abandoned form leaves nothing behind
     * and the database cannot be filled with plans for numbers that do not
     * exist. This is the gate v1 had, and it is worth keeping.
     */
    async function sendCode(): Promise<void> {
        busy.value = true;
        error.value = null;
        notice.value = null;
        fieldErrors.value = {};

        try {
            await api.post('/public/signup/code', { phone: form.value.phone });
            stage.value = 'code';
            cooldown.start();
            notice.value = `We have sent a code to ${form.value.phone}. It lasts five minutes.`;
        } catch (e) {
            fail(e);
        } finally {
            busy.value = false;
        }
    }

    /**
     * Step two: place the order and pay for it.
     *
     * The plan is created pending and a payment is opened before the gateway
     * window appears, so somebody who closes it still leaves a record the
     * office can chase rather than vanishing.
     */
    async function placeOrder(): Promise<void> {
        busy.value = true;
        error.value = null;
        notice.value = null;
        fieldErrors.value = {};

        let checkout: Checkout;

        try {
            const { data } = await api.post('/public/signup', {
                ...form.value,
                email: form.value.email || null,
                society_id: form.value.society_id || null,
                cloth_bundle_id: form.value.cloth_bundle_id || null,
                preferred_time: form.value.preferred_time || null,
                code: code.value,
            });

            checkout = data.data;
        } catch (e) {
            fail(e);
            busy.value = false;

            // A rejected code sends them back a step rather than leaving them
            // staring at a form they cannot submit.
            if (describeError(e).errors.code) {
                code.value = '';
            }

            return;
        }

        const result = await completeCheckout(checkout, {
            name: form.value.name,
            email: form.value.email,
            phone: form.value.phone,
        });

        busy.value = false;

        if (result.ok) {
            stage.value = 'done';
            notice.value = result.message;
            return;
        }

        /*
         * The plan exists either way. Said plainly, because the alternative is
         * somebody filling the whole form in again and ending up with a second
         * plan on the same car - which the server would then refuse, leaving
         * them stuck.
         */
        error.value = result.cancelled
            ? 'Payment cancelled. Your details are saved — call the office to pay, or try again.'
            : result.message + ' Your details are saved, so please do not fill the form in again.';
    }

    return { stage, code, busy, error, notice, fieldErrors, cooldown, sendCode, placeOrder };
}
