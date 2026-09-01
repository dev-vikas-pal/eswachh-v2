<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { refreshAfter } from '@/shared/api/refresh';
import { payForSubscription } from '@/shared/api/checkout';
import RecordPaymentPanel from '@/admin/components/RecordPaymentPanel.vue';
import SubscriptionPaymentsPanel from '@/shared/SubscriptionPaymentsPanel.vue';
import RenewalNotice, { type RenewalTiming } from '@/shared/RenewalNotice.vue';
import { useAuthStore } from '@/shared/stores/auth';

/**
 * What the office can do to one plan, from the list.
 *
 * A menu rather than three buttons per row: a table with three controls on
 * every line is unreadable, and these are all occasional actions.
 */
const props = defineProps<{
    subscriptionId: string;
    status: string;
    car: string;
    amount?: number;
    customerName?: string;
    customerPhone?: string;
    /**
     * Where the plan stands against its end date.
     *
     * Shown above the two payment options rather than left to whoever is on the
     * phone to work out from the Renews column. Taking money on a plan with
     * four months still to run is a fine thing to do and is not stopped - but
     * doing it without noticing is how a customer gets told the wrong thing
     * about when their cleaning runs to.
     */
    timing?: RenewalTiming | null;
}>();

const emit = defineEmits<{ (e: 'edit'): void }>();
const recording = ref(false);
const paymentsOpen = ref(false);

const auth = useAuthStore();
const queryClient = useQueryClient();

const open = ref(false);

/**
 * Where to draw the menu.
 *
 * It is teleported to the body rather than positioned inside the row, because
 * the table scrolls horizontally and `overflow-x-auto` clips anything that
 * escapes it - so the menu was being cut off at the edge of the table exactly
 * when the window was narrow enough to need scrolling.
 */
const trigger = ref<HTMLElement | null>(null);
const at = ref({ top: 0, left: 0 });

function place() {
    const box = trigger.value?.getBoundingClientRect();

    if (!box) return;

    const width = 240;

    at.value = {
        top: box.bottom + 4,
        // Right-aligned to the button, then nudged back on screen if that would
        // put it off the left edge on a phone.
        left: Math.max(8, box.right - width),
    };
}

function toggle() {
    open.value = !open.value;

    if (open.value) place();
}

/*
 * Follow the button if the page moves underneath it. Cheaper than closing on
 * scroll, which is what somebody scrolling a wide table would fight with.
 */
onMounted(() => {
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place);
});
const busy = ref(false);
const notice = ref<string | null>(null);
const error = ref<string | null>(null);
const picking = ref(false);

const { data: cleaners } = useQuery({
    queryKey: computed(() => ['cleaners', props.subscriptionId]),
    enabled: computed(() => picking.value),
    queryFn: async () => (await api.get(`/subscriptions/${props.subscriptionId}/cleaners`)).data,
});

/**
 * @param sticky  Stays until dismissed, rather than clearing itself.
 */
async function run(fn: () => Promise<string>, sticky = false) {
    busy.value = true;
    notice.value = null;
    error.value = null;

    try {
        notice.value = await fn();

        /*
         * Close the menu and say it plainly.
         *
         * The confirmation used to render inside the dropdown, under six other
         * options - so "Payment received" appeared as a cramped line at the
         * bottom of a menu the person had already stopped reading, and money
         * having changed hands is not something to whisper.
         */
        open.value = false;
        picking.value = false;

        /*
         * Assigning a cleaner clears itself after a few seconds; a payment does
         * not.
         *
         * They are not the same kind of news. "Cleaner assigned" is worth a
         * glance and then it is in the way, but somebody who has just taken
         * money over the phone is usually still on that phone, and the
         * confirmation was disappearing while they were reading the invoice
         * number out. It looked exactly like the payment had not gone through.
         */
        if (! sticky) {
            const shown = notice.value;
            setTimeout(() => { if (notice.value === shown) notice.value = null; }, 6000);
        }

        await refreshAfter(queryClient, 'subscriptions');
    } catch (e) {
        error.value = e instanceof Error && !(e as any).response ? e.message : describeError(e).message;
    } finally {
        busy.value = false;
    }
}

/**
 * Take payment now.
 *
 * The whole flow lives in the checkout client: open the payment on the server,
 * hand off to Razorpay, post what comes back to the callback for verifying.
 * Nothing here decides an amount.
 */
const takePayment = () => run(async () => {
    const result = await payForSubscription(props.subscriptionId, {
        name: props.customerName,
        phone: props.customerPhone,
    });

    if (!result.ok) throw new Error(result.message);

    await refreshAfter(queryClient, 'payments');

    // The invoice number with it, because the person taking the payment is
    // usually reading it back to somebody on the phone.
    return result.payment?.invoice_number
        ? `${result.message} Invoice ${result.payment.invoice_number}.`
        : result.message;
}, true);

const remind = () => run(async () => (await api.post(`/subscriptions/${props.subscriptionId}/remind`, {})).data.message);

const assign = (cleanerId: string | null) => run(async () => {
    picking.value = false;
    return (await api.post(`/subscriptions/${props.subscriptionId}/cleaner`, { cleaner_id: cleanerId })).data.message;
});

const setStatus = (status: string) => run(async () =>
    (await api.post(`/subscriptions/${props.subscriptionId}/status`, { status })).data.message);
</script>

<template>
    <div ref="trigger" class="inline-block text-left">
        <button
            type="button"
            class="rounded border border-line-strong px-2 py-1 text-xs text-body transition hover:bg-sunk focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            :aria-expanded="open"
            @click="toggle"
        >
            Actions
        </button>

        <!-- Clicking anywhere else closes it, since the menu no longer sits
             inside anything that would catch the click. -->
        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-40" @click="open = false"></div>

            <div
                v-if="open"
                class="fixed z-50 w-60 rounded-lg border border-line-strong bg-surface p-1.5 text-left shadow-xl"
                :style="{ top: at.top + 'px', left: at.left + 'px' }"
            >
            <template v-if="!picking">
                <button
                    type="button"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk"
                    @click="emit('edit'); open = false"
                >
                    Edit this plan
                    <span class="block text-xs text-faint">Package, cleaning type, dates, cleaner</span>
                </button>

                <!-- Said once, above both ways of taking money. -->
                <RenewalNotice
                    v-if="timing && (timing.overdue || timing.early)"
                    :timing="timing"
                    audience="office"
                    class="mx-1 mb-1 mt-1 !text-xs"
                />

                <button
                    v-if="auth.can('view.payment')"
                    type="button"
                    :disabled="busy"
                    class="block w-full rounded px-3 py-2 text-left text-sm font-medium text-accent-ink transition hover:bg-accent-soft disabled:opacity-50"
                    @click="takePayment"
                >
                    Take payment online
                    <span class="block text-xs font-normal text-faint">Priced by us, confirmed before charging</span>
                </button>

                <button
                    v-if="auth.can('create.payment')"
                    type="button"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk"
                    @click="recording = true"
                >
                    Record a payment
                    <span class="block text-xs text-faint">Cash, UPI or a transfer taken by hand</span>
                </button>

                <!--
                    The other direction of the same link: from an order to what
                    has been paid on it. This is the one somebody reaches for
                    when a customer says they have already paid.
                -->
                <button
                    v-if="auth.can('view.payment')"
                    type="button"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk"
                    @click="paymentsOpen = true; open = false"
                >
                    Payments on this order
                    <span class="block text-xs text-faint">Every charge, refund and abandoned attempt</span>
                </button>

                <button
                    type="button"
                    :disabled="busy"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk disabled:opacity-50"
                    @click="remind"
                >
                    Send renewal reminder
                    <span class="block text-xs text-faint">Once a day, same as the nightly job</span>
                </button>

                <button
                    v-if="auth.can('assign.cleaner')"
                    type="button"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk"
                    @click="picking = true"
                >
                    Assign a cleaner
                    <span class="block text-xs text-faint">Stays with the car through renewals</span>
                </button>

                <button
                    v-if="status === 'active'"
                    type="button"
                    :disabled="busy"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-warn transition hover:bg-warn-soft disabled:opacity-50"
                    @click="setStatus('hold')"
                >
                    Pause this plan
                </button>

                <button
                    v-if="status === 'hold'"
                    type="button"
                    :disabled="busy"
                    class="block w-full rounded px-3 py-2 text-left text-sm text-ok transition hover:bg-ok-soft disabled:opacity-50"
                    @click="setStatus('active')"
                >
                    Restart this plan
                </button>
            </template>

            <template v-else>
                <p class="px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                    Who cleans {{ car }}?
                </p>

                <button
                    v-for="c in cleaners?.data ?? []"
                    :key="c.id"
                    type="button"
                    class="block w-full rounded px-3 py-1.5 text-left text-sm transition hover:bg-sunk"
                    :class="cleaners?.current === c.id ? 'text-accent-ink' : 'text-body'"
                    @click="assign(c.id)"
                >
                    {{ c.name }}
                    <span v-if="cleaners?.current === c.id" class="text-xs">· current</span>
                </button>

                <p v-if="!(cleaners?.data ?? []).length" class="px-3 py-2 text-sm text-muted">
                    No cleaners in this branch yet.
                </p>

                <button
                    type="button"
                    class="mt-1 block w-full rounded border-t border-line px-3 py-1.5 text-left text-xs text-muted hover:bg-sunk"
                    @click="picking = false"
                >
                    Back
                </button>
            </template>

                <p v-if="error" class="mt-1 rounded bg-crit-soft px-3 py-1.5 text-xs text-crit">{{ error }}</p>
            </div>

            <!--
                The answer, where it can actually be seen.
                Fixed to the screen rather than drawn inside a menu that has
                just closed - money changing hands deserves more than a line
                somebody has to go looking for.
            -->
            <div
                v-if="notice"
                class="fixed bottom-6 left-1/2 z-[60] flex -translate-x-1/2 items-center gap-3 rounded-lg border border-ok bg-ok-soft px-5 py-3 shadow-xl"
                role="status"
            >
                <svg class="h-5 w-5 shrink-0 text-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span class="text-sm font-medium text-ink">{{ notice }}</span>
                <button
                    type="button"
                    class="ms-2 text-sm text-body underline hover:text-ink"
                    @click="notice = null"
                >
                    Close
                </button>
            </div>
        </Teleport>

        <div
            v-if="recording"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4 pt-16"
            @click.self="recording = false"
        >
            <div class="w-full max-w-lg rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
                <div class="mb-3 flex items-start gap-3">
                    <h2 class="text-lg font-semibold text-ink">Payment for {{ car }}</h2>
                    <button type="button" class="ms-auto text-sm text-muted hover:text-ink" @click="recording = false">Close</button>
                </div>

                <RecordPaymentPanel
                    :subscription-id="subscriptionId"
                    :suggested-amount="amount ?? 0"
                    @recorded="recording = false; open = false"
                />
            </div>
        </div>

        <SubscriptionPaymentsPanel
            v-if="paymentsOpen"
            :subscription-id="subscriptionId"
            :registration="car"
            @close="paymentsOpen = false"
        />
    </div>
</template>
