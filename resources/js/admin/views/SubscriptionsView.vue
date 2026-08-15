<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { listSubscriptions } from '@/admin/shared/subscriptions.api';
import { useRoute } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import type { Paginated, Subscription } from '@/shared/types';
import SubscriptionActions from '@/admin/components/SubscriptionActions.vue';
import SubscriptionForm from '@/admin/components/SubscriptionForm.vue';
import SortableHeader from '@/admin/components/SortableHeader.vue';
import BulkActionBar from '@/admin/components/BulkActionBar.vue';
import { useRowSelection } from '@/admin/shared/useRowSelection';

const auth = useAuthStore();
const route = useRoute();

const search = ref('');
const status = ref('');
const expiredOnly = ref(false);
const unassignedOnly = ref(false);
const sectorId = ref('');
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

// Any change to the filters starts again at the first page, otherwise you can
// land on page 4 of a two page result and see nothing.
watch([search, status, expiredOnly, unassignedOnly, sectorId, packageId, cleanerId, renewFrom, renewTo, () => auth.selectedBranchId], () => {
    page.value = 1;
});

const { data, isPending, isError, isFetching } = useQuery({
    queryKey: computed(() => [
        'subscriptions', auth.selectedBranchId, search.value, status.value, expiredOnly.value,
        unassignedOnly.value, sectorId.value, packageId.value, cleanerId.value,
        renewFrom.value, renewTo.value, page.value, sort.value, direction.value,
    ]),
    // Keeps the table on screen while the next page loads, instead of blanking.
    placeholderData: keepPreviousData,
    queryFn: (): Promise<Paginated<Subscription>> => listSubscriptions({
        filters: {
            search: search.value,
            status: status.value,
            expired: expiredOnly.value,
            unassigned: unassignedOnly.value,
            sector_id: sectorId.value,
            package_id: packageId.value,
            cleaner_id: cleanerId.value,
            renew_from: renewFrom.value,
            renew_to: renewTo.value,
        },
        page: page.value,
        sort: sort.value,
        direction: direction.value,
    }),
});

const rows = computed(() => data.value?.data ?? []);

const queryClient = useQueryClient();
const selection = useRowSelection(rows);

/** Filter options. Sectors and packages come from the masters. */
const { data: sectors } = useQuery({
    queryKey: ['masters', 'sectors', 'options'],
    queryFn: async () => (await import('@/shared/api/client')).api.get('/masters/sectors').then((r) => r.data.data),
    staleTime: 5 * 60 * 1000,
});

const { data: packages } = useQuery({
    queryKey: ['masters', 'packages', 'options'],
    queryFn: async () => (await import('@/shared/api/client')).api.get('/masters/packages').then((r) => r.data.data),
    staleTime: 5 * 60 * 1000,
});

const { data: cleaners } = useQuery({
    queryKey: ['bulk', 'cleaners'],
    queryFn: async () => (await import('@/admin/shared/subscriptions.api')).cleanersForBranch(),
    staleTime: 5 * 60 * 1000,
});

function clearFilters() {
    search.value = '';
    status.value = '';
    expiredOnly.value = false;
    unassignedOnly.value = false;
    sectorId.value = '';
    packageId.value = '';
    cleanerId.value = '';
    renewFrom.value = '';
    renewTo.value = '';
}

async function afterBulk() {
    selection.clear();
    await queryClient.invalidateQueries({ queryKey: ['subscriptions'] });
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
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Sector</span>
                <select v-model="sectorId" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">All sectors</option>
                    <option v-for="o in sectors ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
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

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Renews from</span>
                <input v-model="renewFrom" type="date" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">to</span>
                <input v-model="renewTo" type="date" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="expiredOnly" type="checkbox" class="rounded border-line-strong" />
                Overdue only
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="unassignedOnly" type="checkbox" class="rounded border-line-strong" />
                No cleaner
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
                        <td class="px-3 py-2 text-right tabular-nums text-body">
                            {{ row.paid.formatted }}
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
        <SubscriptionForm
            v-if="creating || editingId"
            :subscription-id="editingId"
            @close="creating = false; editingId = null"
            @saved="creating = false; editingId = null"
        />
    </div>
</template>
