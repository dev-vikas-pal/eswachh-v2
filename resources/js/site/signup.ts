import { computed, ref, type Ref } from 'vue';
import { api, describeError, type ValidationErrors } from '@/shared/api/client';
import { completeCheckout, type Checkout, type PaymentReceipt } from '@/shared/api/checkout';
import { setPaymentPhase } from '@/shared/paymentProgress';
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

    /** The receipt, once there is one. Drives the confirmation page. */
    const receipt = ref<PaymentReceipt | null>(null);

    /** How long before "send it again" is allowed. */
    const cooldown = useCodeCooldown();

    function fail(e: unknown) {
        const described = describeError(e);
        error.value = described.message;
        fieldErrors.value = described.errors;
    }

    /*
     * Field-level checks, shown as somebody leaves each box.
     *
     * The form used to say nothing until Pay was pressed, which meant a typo in
     * the first field was reported after the code had been sent and the price
     * worked out - three steps too late to be useful, and next to a payment
     * button that then refused to work.
     *
     * Deliberately a small set: the things the server will certainly refuse.
     * Anything cleverer belongs on the server, which checks all of it again.
     */
    const touched = ref<Record<string, boolean>>({});

    const RULES: Record<string, (f: SignupForm) => string | null> = {
        name: (f) => (f.name.trim().length >= 2 ? null : 'Please give your name.'),

        phone: (f) => (/^[6-9]\d{9}$/.test(f.phone.replace(/\D/g, ''))
            ? null
            : 'A ten digit Indian mobile number, starting 6 to 9.'),

        email: (f) => (!f.email.trim() || /^\S+@\S+\.\S+$/.test(f.email)
            ? null
            : 'That does not look like an email address.'),

        // Loose on purpose: plates vary, and refusing a real one is worse than
        // letting the server have the last word.
        registration: (f) => (f.registration.replace(/\s/g, '').length >= 6
            ? null
            : 'The full car number, as on the plate.'),

        vehicle_model_id: (f) => (f.vehicle_model_id ? null : 'Pick the car.'),
        sector_id: (f) => (f.sector_id ? null : 'Pick the sector the car is kept in.'),
        house_no: (f) => (f.house_no.trim() ? null : 'Flat or house number.'),
    };

    /** Mark a field as left, so its message may now be shown. */
    function touch(field: string): void {
        touched.value[field] = true;
    }

    /**
     * What to show under a field: the server's complaint if it has one,
     * otherwise ours - and only once they have actually been in the box.
     */
    function errorFor(field: string): string | null {
        if (fieldErrors.value[field]?.length) {
            return fieldErrors.value[field][0];
        }

        if (! touched.value[field]) {
            return null;
        }

        return RULES[field]?.(form.value) ?? null;
    }

    /** Every field that would fail, whether or not it has been touched. */
    const problems = computed(
        () => Object.keys(RULES).filter((field) => RULES[field](form.value) !== null),
    );

    const ready = computed(() => problems.value.length === 0);

    /** Show every message at once, for when somebody presses on regardless. */
    function touchEverything(): void {
        for (const field of Object.keys(RULES)) {
            touched.value[field] = true;
        }
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
            /*
             * The car number and the sector go with the request.
             *
             * Both can refuse this signup, and the server checks them here so
             * the customer hears about it now rather than after the code has
             * been sent, typed and read back - which is the whole form filled
             * in, and nothing to do but start again.
             */
            await api.post('/public/signup/code', {
                phone: form.value.phone,
                registration: form.value.registration,
                sector_id: form.value.sector_id || null,
            });
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

        // The screen is held from here, not from when the gateway appears: the
        // plan is being created on the server and the customer should not be
        // pressing the button again while it is.
        setPaymentPhase('opening');

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
            setPaymentPhase('idle');
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
            receipt.value = result.payment ?? null;

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

    return {
        stage, code, busy, error, notice, fieldErrors, receipt, cooldown, sendCode, placeOrder,
        touch, errorFor, ready, problems, touchEverything,
    };
}
