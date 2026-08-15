<script setup lang="ts">
import SortableHeader from '@/admin/components/SortableHeader.vue';
import DateRangeFilter from '@/shared/DateRangeFilter.vue';
import InvoicePanel from '@/shared/InvoicePanel.vue';
import PaymentDetailPanel from '@/shared/PaymentDetailPanel.vue';
import { usePaymentsScreen } from '@/admin/views/PaymentsView';

/**
 * Payments.
 *
 * The logic is in PaymentsView.ts beside this file; what is left here is the
 * markup and what it binds to.
 */
const {
    receiptFor, detailFor, search, status, page,
    rows, meta, captured, isPending, isError, isFetching,
    activeSort, activeDirection,
    onSort, onPeriod, openSubscription,
    money, statusValue, statusClass, when,
} = usePaymentsScreen();
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

            <DateRangeFilter label="Paid" @change="onPeriod" />
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
