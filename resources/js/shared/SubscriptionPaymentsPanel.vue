<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { describeError } from '@/shared/api/client';
import { fetchPaymentsForSubscription, money, statusTone } from '@/shared/payments.api';
import PaymentDetailPanel from '@/shared/PaymentDetailPanel.vue';

/**
 * Every payment against one order.
 *
 * The list somebody reaches for when a customer says they have already paid.
 * Abandoned attempts are shown as well as completed ones, deliberately: "there
 * is an initiated payment from Tuesday that never completed" is the actual
 * answer more often than not, and hiding it makes the screen agree with the
 * customer for the wrong reason.
 */
const props = defineProps<{ subscriptionId: string; registration?: string | null }>();
defineEmits<{ (e: 'close'): void }>();

const detailFor = ref<string | null>(null);

const { data, isLoading, error } = useQuery({
    queryKey: computed(() => ['payments', 'for-plan', props.subscriptionId]),
    queryFn: () => fetchPaymentsForSubscription(props.subscriptionId),
});

const rows = computed(() => data.value ?? []);

/** What has actually been collected, as opposed to what was attempted. */
const collected = computed(() =>
    rows.value.filter((r) => r.status === 'captured').reduce((sum, r) => sum + r.amount, 0),
);
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10" @click.self="$emit('close')">
        <div class="w-full max-w-2xl rounded-lg border border-line-strong bg-surface shadow-xl">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">
                    Payments on {{ registration ?? 'this order' }}
                </h2>

                <button
                    type="button"
                    class="ms-auto rounded border border-line-strong px-3 py-1.5 text-sm text-body transition hover:bg-sunk"
                    @click="$emit('close')"
                >
                    Close
                </button>
            </header>

            <p v-if="isLoading" class="px-5 py-10 text-sm text-muted">Loading…</p>

            <p v-else-if="error" class="m-5 rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
                {{ describeError(error).message }}
            </p>

            <p v-else-if="!rows.length" class="px-5 py-10 text-center text-sm text-muted">
                Nothing has been paid on this order yet.
            </p>

            <div v-else class="px-5 py-4">
                <p class="mb-3 text-sm text-body">
                    Collected so far
                    <strong class="ms-1 text-base font-semibold tabular-nums text-ink">{{ money(collected) }}</strong>
                </p>

                <div class="overflow-x-auto rounded border border-line">
                    <table class="w-full text-sm">
                        <thead class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                            <tr>
                                <th scope="col" class="px-3 py-2">Invoice</th>
                                <th scope="col" class="px-3 py-2">For</th>
                                <th scope="col" class="px-3 py-2">Paid on</th>
                                <th scope="col" class="px-3 py-2">Status</th>
                                <th scope="col" class="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id" class="border-b border-line last:border-0 hover:bg-sunk">
                                <td class="px-3 py-2">
                                    <button
                                        type="button"
                                        class="tabular-nums text-accent underline-offset-2 hover:underline"
                                        @click="detailFor = row.id"
                                    >
                                        {{ row.invoice_number ?? 'Not completed' }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-body">{{ row.purpose_label }}</td>
                                <td class="px-3 py-2 tabular-nums text-body">{{ row.paid_at ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusTone(row.status)">
                                        {{ row.status_label }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-ink">{{ money(row.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PaymentDetailPanel
            v-if="detailFor"
            :payment-id="detailFor"
            @close="detailFor = null"
            @open="detailFor = $event"
        />
    </div>
</template>
