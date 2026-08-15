<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import type { Payment, PaymentPage } from '@/shared/types';
import SortableHeader from '@/admin/components/SortableHeader.vue';
import InvoicePanel from '@/shared/InvoicePanel.vue';
import PaymentDetailPanel from '@/shared/PaymentDetailPanel.vue';

const auth = useAuthStore();
const router = useRouter();

/** Which payment the receipt is open for, if any. */
const receiptFor = ref<string | null>(null);

/** And which one the full detail is open for. */
const detailFor = ref<string | null>(null);

const search = ref('');
const status = ref('');
const from = ref('');
const to = ref('');
const page = ref(1);
const sort = ref('created');
const direction = ref<'asc' | 'desc'>('asc');

/**
 * What the server says it sorted by, falling back to what we asked for while
 * the first response is in flight - otherwise the arrow flickers on every click.
 */
const activeSort = computed(() => meta.value?.sort ?? sort.value);
const activeDirection = computed<'asc' | 'desc'>(() => meta.value?.direction ?? direction.value);

function onSort(field: string, next: 'asc' | 'desc') {
    sort.value = field;
    direction.value = next;
    page.value = 1;
}

watch([search, status, from, to, () => auth.selectedBranchId], () => {
    page.value = 1;
});

const { data, isPending, isError, isFetching } = useQuery({
    queryKey: computed(() => [
        'payments', auth.selectedBranchId, search.value, status.value, from.value, to.value, page.value, sort.value, direction.value,
    ]),
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<PaymentPage> => {
        const { data } = await api.get('/payments', {
            params: {
                page: page.value,
                search: search.value || undefined,
                status: status.value || undefined,
                from: from.value || undefined,
                to: to.value || undefined,
                sort: sort.value,
                direction: direction.value,
            },
        });
        return data;
    },
});

const rows = computed(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

/**
 * The one figure people act on, so it is stated in full rather than
 * abbreviated, and it always describes exactly the rows below it.
 */
const captured = computed(() =>
    new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        maximumFractionDigits: 0,
    }).format((meta.value?.total_captured_paise ?? 0) / 100),
);

function money(paise: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(paise / 100);
}

function statusValue(row: Payment): string {
    return typeof row.status === 'string' ? row.status : row.status.value;
}

function statusClass(row: Payment): string {
    switch (statusValue(row)) {
        case 'captured':
            return 'bg-ok-soft text-ok';
        case 'failed':
            return 'bg-crit-soft text-crit';
        case 'refunded':
            return 'bg-info-soft text-info';
        default:
            // Still in flight, or abandoned. Amber, because it is neither good
            // news nor a failure yet.
            return 'bg-warn-soft text-warn';
    }
}

/**
 * Follow a payment back to the order it paid for.
 *
 * The subscriptions list opens filtered to that one plan, rather than a
 * separate screen: the row there already carries every action somebody would
 * want next - edit it, record another payment, message the customer.
 */
async function openSubscription(id: string) {
    detailFor.value = null;
    await router.push({ name: 'subscriptions', query: { plan: id } });
}

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-baseline gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Payments</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>

            <span class="ms-auto text-sm text-muted">
                Received in this view
                <strong class="ms-1 text-base font-semibold tabular-nums text-ink">{{ captured }}</strong>
            </span>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
            <label class="grow sm:grow-0">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                <input
                    v-model.trim="search"
                    type="search"
                    placeholder="Invoice, reference, name or phone"
                    class="w-full rounded border border-line-strong px-3 py-1.5 text-sm sm:w-72 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Status</span>
                <select
                    v-model="status"
                    class="rounded border border-line-strong px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option value="">All</option>
                    <option value="captured">Completed</option>
                    <option value="initiated">Initiated</option>
                    <option value="failed">Failed</option>
                    <option value="refunded">Refunded</option>
                </select>
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">From</span>
                <input
                    v-model="from"
                    type="date"
                    class="rounded border border-line-strong px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">To</span>
                <input
                    v-model="to"
                    type="date"
                    class="rounded border border-line-strong px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>
        </div>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            The list could not be loaded. Please refresh.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <SortableHeader field="invoice" :sort="activeSort" :direction="activeDirection" @sort="onSort">Invoice</SortableHeader>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">For</th>
                        <SortableHeader field="paid" :sort="activeSort" :direction="activeDirection" @sort="onSort">Paid on</SortableHeader>
                        <SortableHeader field="method" :sort="activeSort" :direction="activeDirection" @sort="onSort">Method</SortableHeader>
                        <SortableHeader field="amount" align="right" :sort="activeSort" :direction="activeDirection" @sort="onSort">Amount</SortableHeader>
                        <SortableHeader field="status" :sort="activeSort" :direction="activeDirection" @sort="onSort">Status</SortableHeader>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending">
                        <td colspan="7" class="px-3 py-6 text-center text-muted">Loading…</td>
                    </tr>

                    <tr v-else-if="!rows.length">
                        <td colspan="7" class="px-3 py-6 text-center text-muted">
                            Nothing matches those filters.
                        </td>
                    </tr>

                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-line last:border-0 hover:bg-sunk"
                    >
                        <td class="px-3 py-2 whitespace-nowrap font-medium tabular-nums text-ink">
                            <!-- Only a captured payment has a receipt: one for
                                 an abandoned checkout would say money changed
                                 hands when it did not. -->
                            <button
                                v-if="row.invoice_number && statusValue(row) === 'captured'"
                                type="button"
                                class="text-accent underline-offset-2 hover:underline"
                                title="Show the receipt"
                                @click="receiptFor = row.id"
                            >
                                {{ row.invoice_number }}
                            </button>
                            <span v-else>{{ row.invoice_number ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.customer?.name ?? '—' }}
                            <div class="text-xs text-faint">{{ row.customer?.phone }}</div>
                        </td>
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="text-accent underline-offset-2 hover:underline"
                                title="Everything about this payment"
                                @click="detailFor = row.id"
                            >
                                {{ row.purpose_label }}
                            </button>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums text-body">
                            {{ when(row.paid_at) }}
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.method ?? '—' }}
                            <!-- Cash is where money goes missing, so a payment
                                 somebody entered by hand says so on the row. -->
                            <span v-if="row.recorded_by_hand" class="ms-1 text-xs text-faint">
                                entered by hand
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">
                            {{ money(row.amount_paise) }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass(row)">
                                {{ row.status_label }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center gap-3">
            <button
                type="button"
                class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50"
                :disabled="page <= 1"
                @click="page--"
            >
                Previous
            </button>
            <span class="text-sm text-body tabular-nums">
                Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} total
            </span>
            <button
                type="button"
                class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50"
                :disabled="page >= meta.last_page"
                @click="page++"
            >
                Next
            </button>
        </div>
        <InvoicePanel v-if="receiptFor" :payment-id="receiptFor" @close="receiptFor = null" />

        <!-- Opening a sibling swaps the id in place rather than stacking panels. -->
        <PaymentDetailPanel
            v-if="detailFor"
            :payment-id="detailFor"
            @close="detailFor = null"
            @open="detailFor = $event"
            @open-subscription="openSubscription"
        />
    </div>
</template>
