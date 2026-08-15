<script setup lang="ts">
import { ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { fetchPayments } from '@/portal/portal.api';
import { describeError } from '@/shared/api/client';
import InvoicePanel from '@/shared/InvoicePanel.vue';
import PaymentDetailPanel from '@/shared/PaymentDetailPanel.vue';

/**
 * Receipts.
 *
 * Only payments that actually went through. A row saying "failed" next to
 * somebody's own name reads as though something is wrong with their account,
 * when all it means is that they closed a checkout window once.
 */
const receiptFor = ref<string | null>(null);
const detailFor = ref<string | null>(null);

const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'payments'],
    queryFn: fetchPayments,
});

/** The server sends a full timestamp; a receipt only needs the day. */
function on(iso: unknown): string {
    if (typeof iso !== 'string') return '—';

    return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** Rupees, already divided by the server so nothing is rounded twice. */
function money(rupees: unknown): string {
    if (typeof rupees !== 'number') return '—';

    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <header>
            <h1 class="text-xl font-semibold text-ink">Payments</h1>
            <p class="text-sm text-muted">Everything you have paid us, most recent first.</p>
        </header>

        <p v-if="isLoading" class="text-sm text-muted">Loading…</p>

        <p v-else-if="error" class="rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
            {{ describeError(error).message }}
        </p>

        <p
            v-else-if="!(data?.data ?? []).length"
            class="rounded border border-line bg-surface px-4 py-6 text-center text-sm text-muted"
        >
            No payments yet.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line-strong bg-surface">
            <table class="w-full min-w-[34rem] text-sm">
                <thead class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-3 py-2">Date</th>
                        <th scope="col" class="px-3 py-2">Invoice</th>
                        <th scope="col" class="px-3 py-2">For</th>
                        <th scope="col" class="px-3 py-2">How</th>
                        <th scope="col" class="px-3 py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in data!.data" :key="row.id as string" class="border-b border-line last:border-0">
                        <td class="px-3 py-2 tabular-nums text-body">{{ on(row.paid_at) }}</td>
                        <td class="px-3 py-2 tabular-nums text-body">
                            <button v-if="row.invoice_number" type="button" class="text-accent underline-offset-2 hover:underline" @click="receiptFor = row.id as string">
                                {{ row.invoice_number }}
                            </button>
                            <span v-else>—</span>
                        </td>
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="text-accent underline-offset-2 hover:underline"
                                @click="detailFor = row.id as string"
                            >
                                {{ row.purpose_label ?? 'Details' }}
                            </button>
                        </td>
                        <td class="px-3 py-2 text-body">{{ row.method ?? '—' }}</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums text-ink">
                            {{ money(row.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <InvoicePanel v-if="receiptFor" :payment-id="receiptFor" @close="receiptFor = null" />

        <PaymentDetailPanel
            v-if="detailFor"
            :payment-id="detailFor"
            @close="detailFor = null"
            @open="detailFor = $event"
        />
    </div>
</template>
