<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { payForSubscription } from '@/shared/api/checkout';
import RecordPaymentPanel from '@/admin/components/RecordPaymentPanel.vue';
import SubscriptionPaymentsPanel from '@/shared/SubscriptionPaymentsPanel.vue';
import { useAuthStore } from '@/shared/stores/auth';

/**
 * What the office can do to one plan, from the list.
 *
 * A menu rather than three buttons per row: a table with three controls on
 * every line is unreadable, and these are all occasional actions.
 */
const props = defineProps<{ subscriptionId: string; status: string; car: string; amount?: number; customerName?: string; customerPhone?: string }>();

const emit = defineEmits<{ (e: 'edit'): void }>();
const recording = ref(false);
const paymentsOpen = ref(false);

const auth = useAuthStore();
const queryClient = useQueryClient();

const open = ref(false);
const busy = ref(false);
const notice = ref<string | null>(null);
const error = ref<string | null>(null);
const picking = ref(false);

const { data: cleaners } = useQuery({
    queryKey: computed(() => ['cleaners', props.subscriptionId]),
    enabled: computed(() => picking.value),
    queryFn: async () => (await api.get(`/subscriptions/${props.subscriptionId}/cleaners`)).data,
});

async function run(fn: () => Promise<string>) {
    busy.value = true;
    notice.value = null;
    error.value = null;

    try {
        notice.value = await fn();
        await queryClient.invalidateQueries({ queryKey: ['subscriptions'] });
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

    await queryClient.invalidateQueries({ queryKey: ['payments'] });
    return result.message;
});

const remind = () => run(async () => (await api.post(`/subscriptions/${props.subscriptionId}/remind`, {})).data.message);

const assign = (cleanerId: string | null) => run(async () => {
    picking.value = false;
    return (await api.post(`/subscriptions/${props.subscriptionId}/cleaner`, { cleaner_id: cleanerId })).data.message;
});

const setStatus = (status: string) => run(async () =>
    (await api.post(`/subscriptions/${props.subscriptionId}/status`, { status })).data.message);
</script>

<template>
    <div class="relative inline-block text-left">
        <button
            type="button"
            class="rounded border border-line-strong px-2 py-1 text-xs text-body transition hover:bg-sunk focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            :aria-expanded="open"
            @click="open = !open"
        >
            Actions
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-30 mt-1 w-60 rounded-lg border border-line-strong bg-surface p-1.5 text-left shadow-lg"
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

            <p v-if="notice" class="mt-1 rounded bg-ok-soft px-3 py-1.5 text-xs text-ok">{{ notice }}</p>
            <p v-if="error" class="mt-1 rounded bg-crit-soft px-3 py-1.5 text-xs text-crit">{{ error }}</p>
        </div>

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
