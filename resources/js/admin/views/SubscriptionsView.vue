<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { listSubscriptions } from '@/admin/shared/subscriptions.api';
import { refreshAfter } from '@/shared/api/refresh';
import { useRoute } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import type { Paginated, Subscription } from '@/shared/types';
import SubscriptionActions from '@/admin/components/SubscriptionActions.vue';
import SubscriptionForm from '@/admin/components/SubscriptionForm.vue';
import SortableHeader from '@/admin/components/SortableHeader.vue';
import BulkActionBar from '@/admin/components/BulkActionBar.vue';
import DateRangeFilter from '@/shared/DateRangeFilter.vue';
import PaymentDetailPanel from '@/shared/PaymentDetailPanel.vue';
import SubscriptionPaymentsPanel from '@/shared/SubscriptionPaymentsPanel.vue';
import { useRowSelection } from '@/admin/shared/useRowSelection';

const auth = useAuthStore();
const route = useRoute();

const search = ref('');
const status = ref('');
const expiredOnly = ref(false);
const currentOnly = ref(false);
const unassignedOnly = ref(false);
const packageId = ref('');
const cleanerId = ref('');
const renewFrom = ref('');
const renewTo = ref('');
const page = ref(1);
const creating = ref(false);
const editingId = ref<string | null>(null);
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

/**
 * Arriving here from a payment: `?plan=<id>` opens that order.
 *
 * The plan's own form rather than the list filtered down to one row - somebody
 * following a payment back wants to see what was bought and correct it, and
 * the form is where all of that already is.
 */
watch(
    () => route.query.plan,
    (id) => {
        if (typeof id === 'string' && id) editingId.value = id;
    },
    { immediate: true },
);

/**
 * Filters carried in from somewhere else - a dashboard tile, a bookmark.
 *
 * The point of a count on a dashboard is that somebody wants to see the rows
 * behind it, and "On hold: 4" followed by a list of everything is a dead end.
 * Read once on arrival rather than kept in sync both ways: the filters are then
 * ordinary controls the person can change, and the URL is where they came from
 * rather than a second source of truth fighting them.
 */
watch(
    () => route.query,
    (query) => {
        if (typeof query.status === 'string') status.value = query.status;
        if (query.expired === '1') expiredOnly.value = true;
        if (query.current === '1') currentOnly.value = true;
        if (query.unassigned === '1') unassignedOnly.value = true;
        if (typeof query.cleaner_id === 'string') cleanerId.value = query.cleaner_id;
        if (typeof query.package_id === 'string') packageId.value = query.package_id;
        if (typeof query.search === 'string') search.value = query.search;
    },
    { immediate: true },
);

// Any change to the filters starts again at the first page, otherwise you can
// land on page 4 of a two page result and see nothing.
watch([search, status, expiredOnly, currentOnly, unassignedOnly, packageId, cleanerId, renewFrom, renewTo, () => auth.selectedSectorId], () => {
    page.value = 1;
});

const { data, isPending, isError, isFetching } = useQuery({
    queryKey: computed(() => [
        'subscriptions', auth.selectedSectorId, search.value, status.value, expiredOnly.value,
        currentOnly.value, unassignedOnly.value, packageId.value, cleanerId.value,
        renewFrom.value, renewTo.value, page.value, sort.value, direction.value,
    ]),
    // Keeps the table on screen while the next page loads, instead of blanking.
    placeholderData: keepPreviousData,
    queryFn: (): Promise<Paginated<Subscription>> => listSubscriptions({
        filters: {
            search: search.value,
            status: status.value,
            expired: expiredOnly.value,
            current: currentOnly.value,
            unassigned: unassignedOnly.value,
            package_id: packageId.value,
            cleaner_id: cleanerId.value,
            renew_from: renewFrom.value,
            renew_to: renewTo.value,
        },
        // The one in the top bar. Narrowing the screens is a single control,
        // not one per page - see SectorSelector.
        sectorId: auth.selectedSectorId,
        page: page.value,
        sort: sort.value,
        direction: direction.value,
    }),
});

const rows = computed(() => data.value?.data ?? []);

const queryClient = useQueryClient();
const selection = useRowSelection(rows);

/*
 * Filter options.
 *
 * Not from /masters: that screen is administrator-only, so every one of these
 * lists 403'd for a franchise owner and the dropdowns rendered empty with
 * nothing to say why. Packages are the public price list, which is exactly what
 * this needs and is readable by anybody.
 */
const { data: packages } = useQuery({
    queryKey: ['catalogue', 'packages'],
    queryFn: async () => (await import('@/shared/api/client')).api.get('/public/catalogue')
        .then((r) => r.data.data.packages as Array<{ id: string; name: string }>),
    staleTime: 5 * 60 * 1000,
});

const { data: cleaners } = useQuery({
    queryKey: ['bulk', 'cleaners'],
    queryFn: async () => (await import('@/admin/shared/subscriptions.api')).cleanersInSectors(),
    staleTime: 5 * 60 * 1000,
});

/**
 * Bumped to remount the date filter when Clear is pressed.
 *
 * The filter owns its own dropdown, so clearing the two dates behind its back
 * would leave it still reading "This month" above a list that is no longer
 * filtered by anything.
 */
const filterEpoch = ref(0);

/** Which payment is open, and which plan has its history open. */
const paymentFor = ref<string | null>(null);
const historyFor = ref<{ id: string; car: string | null } | null>(null);

function onRenewalPeriod(range: { from: string; to: string }) {
    renewFrom.value = range.from;
    renewTo.value = range.to;
    page.value = 1;
}

function clearFilters() {
    filterEpoch.value++;
    search.value = '';
    status.value = '';
    expiredOnly.value = false;
    currentOnly.value = false;
    unassignedOnly.value = false;
    packageId.value = '';
    cleanerId.value = '';
    renewFrom.value = '';
    renewTo.value = '';
}

async function afterBulk() {
    selection.clear();
    await refreshAfter(queryClient, 'subscriptions');
}
const meta = computed(() => data.value?.meta);

function statusClass(row: Subscription): string {
    if (row.status.value === 'hold') return 'bg-crit-soft text-crit';
    if (row.is_expired) return 'bg-warn-soft text-warn';
    if (row.status.value === 'active') return 'bg-ok-soft text-ok';
    return 'bg-ground text-body';
}

/** Expired is a state of an active period, so it is labelled, not replaced. */
function statusLabel(row: Subscription): string {
    return row.is_expired ? 'Active · overdue' : row.status.label;
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Subscriptions</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>

            <button v-if="auth.can('create.subscription')" type="button" class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent" @click="creating = true">New plan</button>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
            <label class="grow sm:grow-0">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                <input
                    v-model.trim="search"
                    type="search"
                    placeholder="Car number, name or phone"
                    class="w-full rounded border border-line-strong px-3 py-1.5 text-sm sm:w-64 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Status</span>
                <select
                    v-model="status"
                    class="rounded border border-line-strong px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="hold">On hold</option>
                    <option value="pending">Pending</option>
                    <option value="ended">Ended</option>
                </select>
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Cleaner</span>
                <select v-model="cleanerId" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">Anyone</option>
                    <option v-for="o in cleaners ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Package</span>
                <select v-model="packageId" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">All packages</option>
                    <option v-for="o in packages ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
            </label>

            <!--
                Renewal dates run forwards as often as backwards - "renewing
                this week" is the morning's work - so this one keeps the same
                presets but is labelled for what it filters.
            -->
            <DateRangeFilter :key="filterEpoch" label="Renews" @change="onRenewalPeriod" />

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="expiredOnly" type="checkbox" class="rounded border-line-strong" />
                Overdue only
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="unassignedOnly" type="checkbox" class="rounded border-line-strong" />
                No cleaner
            </label>

            <!--
                Only offered once something has switched it on, which in practice
                means arriving from the dashboard. It is the opposite of Overdue
                rather than a filter somebody reaches for, and a permanent third
                checkbox for it would be clutter.
            -->
            <label v-if="currentOnly" class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="currentOnly" type="checkbox" class="rounded border-line-strong" />
                In date only
            </label>

            <button type="button" class="mb-0.5 rounded border border-line-strong px-3 py-1.5 text-sm text-body transition hover:bg-sunk" @click="clearFilters">
                Clear
            </button>
        </div>

        <BulkActionBar
            v-if="selection.any.value"
            :ids="selection.ids.value"
            :count="selection.count.value"
            @done="afterBulk"
        />

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            The list could not be loaded. Please refresh.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <th class="w-8 px-3 py-2">
                            <input
                                type="checkbox"
                                class="rounded border-line-strong"
                                :checked="selection.allOnPage.value"
                                title="Select everything on this page"
                                @change="selection.toggleAll()"
                            />
                        </th>
                        <th class="px-3 py-2 font-medium">Car</th>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">Cleaner</th>
                        <SortableHeader field="renews" :sort="activeSort" :direction="activeDirection" @sort="onSort">Renews</SortableHeader>
                        <SortableHeader field="amount" align="right" :sort="activeSort" :direction="activeDirection" @sort="onSort">Paid</SortableHeader>
                        <SortableHeader field="status" :sort="activeSort" :direction="activeDirection" @sort="onSort">Status</SortableHeader>
                        <th class="px-3 py-2 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending">
                        <td colspan="8" class="px-3 py-6 text-center text-muted">Loading…</td>
                    </tr>

                    <tr v-else-if="!rows.length">
                        <td colspan="8" class="px-3 py-6 text-center text-muted">
                            Nothing matches those filters.
                        </td>
                    </tr>

                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-line last:border-0 hover:bg-sunk"
                    >
                        <td class="px-3 py-2">
                            <input
                                type="checkbox"
                                class="rounded border-line-strong"
                                :checked="selection.isSelected(row.id)"
                                :aria-label="'Select ' + (row.vehicle?.registration ?? 'this plan')"
                                @change="selection.toggle(row.id)"
                            />
                        </td>
                        <td class="px-3 py-2 font-medium text-ink">
                            {{ row.vehicle?.registration ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.customer?.name ?? '—' }}
                            <div class="text-xs text-faint">{{ row.customer?.phone }}</div>
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.vehicle?.cleaner?.name ?? 'Not assigned' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap text-body tabular-nums">
                            {{ row.period.end ?? '—' }}
                        </td>
                        <!--
                            The total, then the way into how it was made up.
                            A plan renewed six times has six payments behind one
                            figure, and "which one was the ₹949 in March" is the
                            question this column gets asked.
                        -->
                        <td class="px-3 py-2 text-right tabular-nums text-body">
                            {{ row.paid.formatted }}

                            <span class="mt-0.5 block text-xs">
                                <button
                                    v-if="row.last_payment"
                                    type="button"
                                    class="text-accent underline-offset-2 hover:underline"
                                    :title="'Last payment on ' + (row.last_payment.paid_at ?? 'an unknown date')"
                                    @click="paymentFor = row.last_payment!.id"
                                >
                                    {{ row.last_payment.paid_at ?? 'latest' }}
                                </button>

                                <!--
                                    Money recorded against the plan with no
                                    receipt behind it. Almost all of these came
                                    across from v1, which kept the amount on the
                                    order and not always the payment.

                                    It used to say "nothing yet" - directly under
                                    the figure that had just said ₹779 was paid.
                                    A column that contradicts itself is worse
                                    than one that admits what it does not know.
                                -->
                                <span v-else-if="row.paid.paise > 0" class="text-faint" title="The amount is recorded on the plan, but no payment record is filed against it.">
                                    no receipt on file
                                </span>

                                <span v-else class="text-faint">nothing yet</span>

                                <!--
                                    Offered whenever there is money to look at,
                                    not only when this particular period holds
                                    the receipt: a renewed plan keeps its earlier
                                    payments on the periods they bought, and
                                    those are exactly the ones being looked for.
                                -->
                                <button
                                    v-if="row.last_payment || row.paid.paise > 0"
                                    type="button"
                                    class="ms-2 text-muted underline-offset-2 hover:text-ink hover:underline"
                                    @click="historyFor = { id: row.id, car: row.vehicle?.registration ?? null }"
                                >
                                    History
                                </button>
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass(row)">
                                {{ statusLabel(row) }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <SubscriptionActions
                                :subscription-id="row.id"
                                :status="row.status.value"
                                :car="row.vehicle?.registration ?? 'this car'"
                                :customer-name="row.customer?.name"
                                :customer-phone="row.customer?.phone ?? undefined"
                                :amount="row.amount.paise / 100"
                                :timing="row.timing"
                                @edit="editingId = row.id"
                            />
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
        <PaymentDetailPanel
            v-if="paymentFor"
            :payment-id="paymentFor"
            @close="paymentFor = null"
            @open="paymentFor = $event"
        />

        <SubscriptionPaymentsPanel
            v-if="historyFor"
            :subscription-id="historyFor.id"
            :registration="historyFor.car"
            @close="historyFor = null"
        />

        <SubscriptionForm
            v-if="creating || editingId"
            :subscription-id="editingId"
            @close="creating = false; editingId = null"
            @saved="creating = false; editingId = null"
        />
    </div>
</template>
