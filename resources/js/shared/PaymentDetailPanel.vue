<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { describeError } from '@/shared/api/client';
import { fetchPaymentDetail, money, statusTone } from '@/shared/payments.api';
import InvoicePanel from '@/shared/InvoicePanel.vue';

/**
 * Everything about one payment.
 *
 * Built for the question support actually gets - "what happened to my money" -
 * so the gateway's own ids are on screen and can be read down the phone to
 * Razorpay without anybody opening the database. The other payments on the same
 * plan are here for the same reason: almost every argument about a payment is
 * really about two of them.
 */
const props = defineProps<{ paymentId: string }>();
const emit = defineEmits<{
    (e: 'close'): void;
    /** Jump to another payment on the same plan, in place. */
    (e: 'open', id: string): void;
    /** Open the order this paid for. */
    (e: 'open-subscription', id: string): void;
}>();

const showReceipt = ref(false);

const { data, isLoading, error } = useQuery({
    queryKey: computed(() => ['payment-detail', props.paymentId]),
    queryFn: () => fetchPaymentDetail(props.paymentId),
    retry: false,
});

function at(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

const outstanding = computed(() => data.value?.subscription?.outstanding ?? 0);
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10" @click.self="emit('close')">
        <div class="w-full max-w-3xl rounded-lg border border-line-strong bg-surface shadow-xl">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Payment</h2>

                <span v-if="data" class="rounded px-2 py-0.5 text-xs font-medium" :class="statusTone(data.status)">
                    {{ data.status_label }}
                </span>

                <button
                    type="button"
                    class="ms-auto rounded border border-line-strong px-3 py-1.5 text-sm text-body transition hover:bg-sunk"
                    @click="emit('close')"
                >
                    Close
                </button>
            </header>

            <p v-if="isLoading" class="px-5 py-10 text-sm text-muted">Loading…</p>

            <p v-else-if="error" class="m-5 rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
                {{ describeError(error).message }}
            </p>

            <!-- v-else-if rather than v-else so the template narrows `data`. -->
            <div v-else-if="data" class="flex flex-col gap-5 px-5 py-5">
                <!-- The figure, and what it was for. -->
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-muted">Amount</p>
                        <p class="text-2xl font-bold tabular-nums text-ink">{{ data.amount_formatted }}</p>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wide text-muted">For</p>
                        <p class="text-body">{{ data.purpose_label }}</p>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wide text-muted">Taken</p>
                        <p class="text-body">
                            {{ data.channel === 'online' ? 'Online' : 'At the office' }}
                            <span v-if="data.method" class="text-muted">· {{ data.method }}</span>
                        </p>
                    </div>

                    <div v-if="data.invoice_number">
                        <p class="text-xs uppercase tracking-wide text-muted">Receipt</p>
                        <button
                            v-if="data.has_receipt"
                            type="button"
                            class="font-medium tabular-nums text-accent underline-offset-2 hover:underline"
                            @click="showReceipt = true"
                        >
                            {{ data.invoice_number }}
                        </button>
                        <p v-else class="tabular-nums text-body">{{ data.invoice_number }}</p>
                    </div>
                </div>

                <!-- Who paid, and what they bought. -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <section v-if="data.customer" class="rounded border border-line p-3">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Customer</h3>
                        <p class="font-medium text-ink">{{ data.customer.name }}</p>
                        <p class="text-sm tabular-nums text-body">{{ data.customer.phone ?? '—' }}</p>
                        <p class="text-sm text-muted">{{ data.customer.sector ?? 'No sector' }}</p>
                    </section>

                    <section v-if="data.subscription" class="rounded border border-line p-3">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Order</h3>

                        <button
                            type="button"
                            class="font-semibold uppercase tracking-wide text-accent underline-offset-2 hover:underline"
                            @click="emit('open-subscription', data.subscription.id)"
                        >
                            {{ data.subscription.registration ?? 'This plan' }}
                        </button>

                        <p class="text-sm text-body">
                            {{ [data.subscription.package, data.subscription.service_type, data.subscription.duration].filter(Boolean).join(' · ') || '—' }}
                        </p>
                        <p class="text-sm tabular-nums text-muted">
                            {{ data.subscription.period.start ?? '—' }} to {{ data.subscription.period.end ?? '—' }}
                        </p>

                        <p class="mt-2 text-sm tabular-nums text-body">
                            Plan {{ money(data.subscription.amount) }} · paid {{ money(data.subscription.paid) }}
                        </p>

                        <!-- The number somebody is usually looking for. -->
                        <p v-if="outstanding > 0" class="mt-1 rounded bg-warn-soft px-2 py-1 text-sm font-medium tabular-nums text-warn">
                            {{ money(outstanding) }} still owed on this plan
                        </p>
                    </section>
                </div>

                <!-- What the gateway calls it, so support can quote it. -->
                <section class="rounded border border-line p-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                        At the {{ data.channel === 'online' ? 'gateway' : 'office' }}
                    </h3>

                    <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                        <div v-if="data.gateway.order_id" class="flex gap-2">
                            <dt class="text-muted">Order</dt>
                            <dd class="break-all font-mono text-xs text-body">{{ data.gateway.order_id }}</dd>
                        </div>
                        <div v-if="data.gateway.payment_id" class="flex gap-2">
                            <dt class="text-muted">Payment</dt>
                            <dd class="break-all font-mono text-xs text-body">{{ data.gateway.payment_id }}</dd>
                        </div>
                        <div v-if="data.gateway.reference" class="flex gap-2">
                            <dt class="text-muted">Reference</dt>
                            <dd class="break-all text-body">{{ data.gateway.reference }}</dd>
                        </div>
                        <div v-if="data.verified_by" class="flex gap-2">
                            <dt class="text-muted">Checked by</dt>
                            <dd class="text-body">{{ data.verified_by }}</dd>
                        </div>
                    </dl>

                    <p v-if="data.notes" class="mt-2 border-t border-line pt-2 text-sm text-body">{{ data.notes }}</p>
                </section>

                <!-- What happened, in order. -->
                <section>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">History</h3>

                    <ol class="flex flex-col gap-3 border-s border-line ps-4">
                        <li v-for="(event, i) in data.timeline" :key="i" class="relative">
                            <span class="absolute -start-[1.32rem] top-1.5 h-2 w-2 rounded-full bg-accent"></span>
                            <p class="text-sm font-medium text-ink">{{ event.what }}</p>
                            <p class="text-xs tabular-nums text-muted">{{ at(event.at) }}</p>
                            <p class="text-sm text-body">{{ event.detail }}</p>
                        </li>
                    </ol>
                </section>

                <!-- The rest of the plan's payments, for the duplicate question. -->
                <section v-if="data.others_on_this_plan.length">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                        Other payments on this order
                    </h3>

                    <div class="overflow-x-auto rounded border border-line">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr
                                    v-for="other in data.others_on_this_plan"
                                    :key="other.id"
                                    class="border-b border-line last:border-0 hover:bg-sunk"
                                >
                                    <td class="px-3 py-2">
                                        <button
                                            type="button"
                                            class="tabular-nums text-accent underline-offset-2 hover:underline"
                                            @click="emit('open', other.id)"
                                        >
                                            {{ other.invoice_number ?? 'Not completed' }}
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 text-body">{{ other.purpose_label }}</td>
                                    <td class="px-3 py-2 tabular-nums text-body">{{ other.paid_at ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusTone(other.status)">
                                            {{ other.status_label }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ money(other.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <InvoicePanel v-if="showReceipt" :payment-id="paymentId" @close="showReceipt = false" />
    </div>
</template>
