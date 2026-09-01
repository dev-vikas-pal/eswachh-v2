<script setup lang="ts">
import { ref } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { refreshAfter } from '@/shared/api/refresh';

/**
 * Recording money taken outside the gateway.
 *
 * v1 put these fields on the order form itself, and this is the same fields on
 * the same screen — but they write a real payment rather than columns on the
 * plan. That is what keeps the payments screen and the revenue report unable to
 * disagree: there is one figure, in one place.
 *
 * Recording a payment also moves the plan on, exactly as a card payment does.
 * Without that a cash customer is chased for money they have already handed
 * over.
 */
const props = defineProps<{
    subscriptionId: string;
    /** Prefilled from the plan, so nobody retypes what is already known. */
    suggestedAmount: number;
}>();

const emit = defineEmits<{ (e: 'recorded'): void }>();

const queryClient = useQueryClient();

const form = ref({
    amount: props.suggestedAmount,
    method: 'cash',
    reference: '',
    paid_at: new Date().toISOString().slice(0, 16),
    notes: '',
    extend: true,
});

const saving = ref(false);
const notice = ref<string | null>(null);
const error = ref<string | null>(null);

/** v1's Payment Mode, as the office says it. */
const methods = ['cash', 'upi', 'card', 'bank transfer', 'cheque'];

async function record() {
    saving.value = true;
    notice.value = null;
    error.value = null;

    try {
        const { data } = await api.post('/payments/manual', {
            subscription_id: props.subscriptionId,
            amount_paise: Math.round(Number(form.value.amount) * 100),
            method: form.value.method,
            reference: form.value.reference || null,
            paid_at: form.value.paid_at.replace('T', ' ') + ':00',
            notes: form.value.notes || null,
            extend: form.value.extend,
        });

        notice.value = form.value.extend
            ? `Recorded. The plan is now ${data.subscription.status} to ${data.subscription.period_end}.`
            : 'Recorded. The plan was left as it was.';

        await refreshAfter(queryClient, 'payments');
        emit('recorded');
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="rounded-lg border border-line bg-sunk p-3">
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
            Record a payment taken by hand
        </h3>

        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Amount</span>
                <input
                    v-model="form.amount"
                    type="number" min="1" step="1"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Payment mode</span>
                <select
                    v-model="form.method"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Payment date</span>
                <input
                    v-model="form.paid_at"
                    type="datetime-local"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                    Payment / reference id
                </span>
                <input
                    v-model.trim="form.reference"
                    type="text"
                    placeholder="UPI or bank reference"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Note</span>
                <input
                    v-model.trim="form.notes"
                    type="text"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>
        </div>

        <label class="mt-3 flex items-start gap-2 text-sm text-body">
            <input v-model="form.extend" type="checkbox" class="mt-0.5 rounded border-line-strong" />
            <span>
                Move the plan on
                <span class="block text-xs text-faint">
                    Leave ticked unless this payment has already been applied.
                </span>
            </span>
        </label>

        <p v-if="notice" class="mt-2 rounded bg-ok-soft px-3 py-1.5 text-sm text-ok">{{ notice }}</p>
        <p v-if="error" class="mt-2 rounded bg-crit-soft px-3 py-1.5 text-sm text-crit">{{ error }}</p>

        <button
            type="button"
            :disabled="saving"
            class="mt-3 rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
            @click="record"
        >
            {{ saving ? 'Recording…' : 'Record payment' }}
        </button>

        <p class="mt-2 text-xs text-faint">
            Stamped with your name and the time, and given an invoice number.
        </p>
    </div>
</template>
