<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import SortableHeader from '@/admin/components/SortableHeader.vue';
import DateRangeFilter from '@/shared/DateRangeFilter.vue';

/**
 * What has been said to customers.
 *
 * The question this screen answers is "did we tell them?", which comes up on
 * every chasing call. It also makes a broken integration visible: a column of
 * "not delivered" is far harder to ignore than a warning in a log.
 */
const auth = useAuthStore();

const search = ref('');
const status = ref('');
const from = ref('');
const to = ref('');
const page = ref(1);
const sort = ref('sent');
const direction = ref<'asc' | 'desc'>('desc');

const openBody = ref<string | null>(null);

watch([search, status, from, to, sort, direction], () => { page.value = 1; });

const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: computed(() => [
        'reminders', search.value, status.value, from.value, to.value,
        page.value, sort.value, direction.value, auth.selectedSectorId,
    ]),
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get('/reminders', {
        params: {
            page: page.value,
            search: search.value || undefined,
            // The picker in the top bar.
            sector_id: auth.selectedSectorId || undefined,
            status: status.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            sort: sort.value,
            direction: direction.value,
        },
    })).data,
});

const rows = computed(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

/** One filter, one pair of dates, shared with every other list. */
function onPeriod(range: { from: string; to: string }) {
    from.value = range.from;
    to.value = range.to;
    page.value = 1;
}

/** The day, short enough to sit in a narrow column. */
function onDay(iso: string | null | undefined): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** And the time under it, which is what somebody chasing a message wants. */
function atTime(iso: string | null | undefined): string {
    if (!iso) return '';

    return new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
}

function onSort(field: string, next: 'asc' | 'desc') {
    sort.value = field;
    direction.value = next;
}

const statusClass: Record<string, string> = {
    sent: 'bg-ok-soft text-ok',
    failed: 'bg-crit-soft text-crit',
    suppressed: 'bg-warn-soft text-warn',
    queued: 'bg-sunk text-muted',
};
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Messages sent</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>
        </div>

        <!-- The three numbers worth knowing, before the detail. -->
        <div v-if="meta" class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-line bg-surface p-4">
                <p class="text-2xl font-bold tabular-nums text-ok">{{ meta.sent }}</p>
                <p class="text-xs text-muted">reached the customer</p>
            </div>
            <div class="rounded-lg border border-line bg-surface p-4">
                <p class="text-2xl font-bold tabular-nums" :class="meta.failed ? 'text-crit' : 'text-ink'">
                    {{ meta.failed }}
                </p>
                <p class="text-xs text-muted">failed to send</p>
            </div>
            <div class="rounded-lg border border-line bg-surface p-4">
                <p class="text-2xl font-bold tabular-nums text-warn">{{ meta.suppressed }}</p>
                <!-- Recorded but deliberately not delivered. Never counted as
                     sent, because it did not reach anybody. -->
                <p class="text-xs text-muted">recorded, not delivered</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                <input v-model.trim="search" type="search" placeholder="Name, number or wording" class="w-full rounded border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink sm:w-72 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>
            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Status</span>
                <select v-model="status" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">All</option>
                    <option value="sent">Sent</option>
                    <option value="failed">Failed</option>
                    <option value="suppressed">Not delivered</option>
                </select>
            </label>
            <DateRangeFilter label="Sent" @change="onPeriod" />
        </div>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            {{ describeError(error).message }}
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-muted">
                        <SortableHeader field="sent" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Sent</SortableHeader>
                        <th class="px-3 py-2 text-left font-medium uppercase tracking-wide">Customer</th>
                        <SortableHeader field="recipient" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Number</SortableHeader>
                        <SortableHeader field="purpose" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">About</SortableHeader>
                        <SortableHeader field="status" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Status</SortableHeader>
                        <th class="px-3 py-2 text-right font-medium uppercase tracking-wide">Wording</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending"><td colspan="6" class="px-3 py-6 text-center text-muted">Loading…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="6" class="px-3 py-6 text-center text-muted">Nothing sent yet.</td></tr>
                    <tr v-for="row in rows" :key="row.id" class="border-b border-line last:border-0 hover:bg-sunk">
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums text-body">
                            {{ onDay(row.at ?? row.sent_on) }}
                            <span class="block text-xs text-faint">{{ atTime(row.at) }}</span>
                        </td>
                        <td class="px-3 py-2 text-ink">
                            {{ row.customer ?? '—' }}
                            <div class="text-xs text-faint">{{ row.car }}</div>
                        </td>
                        <td class="px-3 py-2 tabular-nums text-body">{{ row.recipient }}</td>
                        <td class="px-3 py-2 text-body">{{ row.purpose_label }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass[row.status]">
                                {{ row.status_label }}
                            </span>
                            <div v-if="row.suppressed_reason" class="mt-0.5 text-xs text-faint">{{ row.suppressed_reason }}</div>
                            <div v-if="row.error" class="mt-0.5 text-xs text-crit">{{ row.error }}</div>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <button type="button" class="rounded px-2 py-1 text-xs font-medium text-accent-ink hover:bg-accent-soft" @click="openBody = row.body">
                                Read
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center gap-3">
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page <= 1" @click="page--">Previous</button>
            <span class="text-sm tabular-nums text-body">Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} messages</span>
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page >= meta.last_page" @click="page++">Next</button>
        </div>

        <!-- Exactly what the customer received, word for word. -->
        <div v-if="openBody" class="fixed inset-0 z-40 flex items-start justify-center bg-black/30 p-4 pt-24" @click.self="openBody = null">
            <div class="w-full max-w-md rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
                <div class="mb-2 flex items-start gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">What was sent</h2>
                    <button type="button" class="ms-auto text-sm text-muted hover:text-ink" @click="openBody = null">Close</button>
                </div>
                <p class="whitespace-pre-line text-body">{{ openBody }}</p>
            </div>
        </div>
    </div>
</template>
