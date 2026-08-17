<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import type { Complaint, ComplaintPage } from '@/shared/types';
import SortableHeader from '@/admin/components/SortableHeader.vue';

const auth = useAuthStore();
const queryClient = useQueryClient();

const search = ref('');
const status = ref('');
const overdueOnly = ref(false);
const mineOnly = ref(false);
const page = ref(1);
const sort = ref('queue');
const direction = ref<'asc' | 'desc'>('asc');

/**
 * The server's answer wins, falling back to what we asked for while the first
 * response is in flight - otherwise the arrow flickers on every click.
 */
const activeSort = computed(() => meta.value?.sort ?? sort.value);
const activeDirection = computed<'asc' | 'desc'>(() => meta.value?.direction ?? direction.value);

function onSort(field: string, next: 'asc' | 'desc') {
    sort.value = field;
    direction.value = next;
    page.value = 1;
}

const openId = ref<string | null>(null);
const noteText = ref('');
const actionError = ref('');

watch([search, status, overdueOnly, mineOnly, () => auth.selectedSectorId], () => {
    page.value = 1;
});

const { data, isPending, isError, isFetching } = useQuery({
    queryKey: computed(() => [
        'complaints', auth.selectedSectorId, search.value, status.value,
        overdueOnly.value, mineOnly.value, page.value, sort.value, direction.value,
    ]),
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<ComplaintPage> => {
        const { data } = await api.get('/complaints', {
            params: {
                page: page.value,
                search: search.value || undefined,
                status: status.value || undefined,
                overdue: overdueOnly.value ? 1 : undefined,
                mine: mineOnly.value ? 1 : undefined,
                // The picker in the top bar. It was only in the cache key,
                // so the list refetched unchanged and looked broken.
                sector_id: auth.selectedSectorId || undefined,
                sort: sort.value,
                direction: direction.value,
            },
        });
        return data;
    },
});

const rows = computed(() => data.value?.data ?? []);

// ---------------------------------------------------------- handing them over

/**
 * Auto-assignment gives a new complaint to whoever cleans the car. This is the
 * fallback for everything it could not route - a car with no cleaner, a cleaner
 * who has left - and the override for when the office wants somebody else on it.
 */
const canAssign = computed(() => auth.can('assign.complaint'));

const ticked = ref<string[]>([]);
const assignee = ref('');
const assigning = ref(false);
const assignNotice = ref<string | null>(null);

// A different page is a different set of rows; carrying ticks across would
// assign things nobody can see.
watch([rows, page], () => { ticked.value = []; });

const allTicked = computed(() => rows.value.length > 0 && ticked.value.length === rows.value.length);

function toggleAll() {
    ticked.value = allTicked.value ? [] : rows.value.map((r) => r.id);
}

/** Who a complaint may be handed to. The server decides the same list again. */
const { data: options } = useQuery({
    queryKey: ['complaint-options'],
    queryFn: async () => (await api.get('/complaints/options')).data.data,
    staleTime: 5 * 60 * 1000,
});

const assignees = computed<Array<{ id: string; name: string; role: string }>>(
    () => options.value?.assignees ?? [],
);

async function assignTicked() {
    if (!assignee.value || ticked.value.length === 0) return;

    const person = assignees.value.find((a) => a.id === assignee.value)?.name ?? 'them';

    /*
     * Said plainly, because this overrides whoever already holds them. Handing
     * twenty complaints to the wrong person is a tedious thing to undo one at
     * a time.
     */
    if (!confirm(`Give ${ticked.value.length} complaint(s) to ${person}? This replaces whoever holds them now.`)) return;

    assigning.value = true;
    assignNotice.value = null;

    try {
        const { data } = await api.post('/complaints-bulk/assign', {
            ids: ticked.value,
            assignee_id: assignee.value,
        });

        assignNotice.value = data.message;

        // Anything the server refused is worth reading, not swallowing.
        if (data.skipped?.length) assignNotice.value += ' ' + data.skipped.join(' ');

        ticked.value = [];
        await queryClient.invalidateQueries({ queryKey: ['complaints'] });
    } catch (e) {
        assignNotice.value = describeError(e).message;
    } finally {
        assigning.value = false;
    }
}
const meta = computed(() => data.value?.meta);

/** The full record, including the trail, only for the one being read. */
const detail = useQuery({
    queryKey: computed(() => ['complaint', openId.value]),
    enabled: computed(() => openId.value !== null),
    queryFn: async (): Promise<{ data: Complaint }> => {
        const { data } = await api.get(`/complaints/${openId.value}`);
        return data;
    },
});

const move = useMutation({
    mutationFn: async (payload: { id: string; action: string; body: Record<string, unknown> }) => {
        const { data } = await api.post(`/complaints/${payload.id}/${payload.action}`, payload.body);
        return data;
    },
    onSuccess: () => {
        actionError.value = '';
        noteText.value = '';
        queryClient.invalidateQueries({ queryKey: ['complaints'] });
        queryClient.invalidateQueries({ queryKey: ['complaint'] });
    },
    onError: (error) => {
        /*
         * The server refuses illegal moves and names the one it refused, on the
         * status field. Prefer that sentence over the generic wrapper: "A
         * closed complaint cannot become resolved" tells the user what to do,
         * "please check the highlighted fields" does not.
         */
        const described = describeError(error);
        actionError.value = described.errors.status?.[0] ?? described.message;
    },
});

function open(row: Complaint) {
    openId.value = row.id;
    actionError.value = '';
    noteText.value = '';
}

function statusClass(row: Complaint): string {
    if (row.is_overdue) return 'bg-crit-soft text-crit';
    switch (row.status.value) {
        case 'closed':
            return 'bg-ground text-body';
        case 'resolved':
            return 'bg-ok-soft text-ok';
        case 'assigned':
            return 'bg-sky-50 text-sky-700';
        default:
            return 'bg-warn-soft text-warn';
    }
}

/** Overdue is a state of a live complaint, so it is labelled, not replaced. */
function statusLabel(row: Complaint): string {
    return row.is_overdue ? `${row.status.label} · overdue` : row.status.label;
}

function due(row: Complaint): string {
    if (!row.due_at) return '—';
    const hours = Math.round((new Date(row.due_at).getTime() - Date.now()) / 3_600_000);
    if (hours < 0) return `${Math.abs(hours)}h late`;
    return `in ${hours}h`;
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-baseline gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Complaints</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>

            <div v-if="meta" class="ms-auto flex items-center gap-4 text-sm">
                <span class="text-muted">
                    Open <strong class="text-ink tabular-nums">{{ meta.live }}</strong>
                </span>
                <!-- The number that should drive somebody's morning, so it is
                     stated plainly rather than left to be counted. -->
                <span :class="meta.overdue > 0 ? 'text-crit' : 'text-muted'">
                    Overdue <strong class="tabular-nums">{{ meta.overdue }}</strong>
                </span>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
            <label class="grow sm:grow-0">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                <input
                    v-model.trim="search"
                    type="search"
                    placeholder="Reference, name or phone"
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
                    <option value="open">Open</option>
                    <option value="assigned">Assigned</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="overdueOnly" type="checkbox" class="rounded border-line-strong" />
                Overdue only
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="mineOnly" type="checkbox" class="rounded border-line-strong" />
                Assigned to me
            </label>
        </div>

        <!--
            Only once something is ticked. A bar that is always there is a bar
            people stop reading.
        -->
        <div
            v-if="canAssign && ticked.length"
            class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-accent bg-accent-soft p-3"
        >
            <span class="text-sm font-medium text-accent-ink">
                {{ ticked.length }} selected
            </span>

            <select
                v-model="assignee"
                class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            >
                <option value="">Give to…</option>
                <option v-for="person in assignees" :key="person.id" :value="person.id">
                    {{ person.name }} — {{ person.role }}
                </option>
            </select>

            <button
                type="button"
                class="rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                :disabled="!assignee || assigning"
                @click="assignTicked"
            >
                {{ assigning ? 'Assigning…' : 'Assign' }}
            </button>

            <button
                type="button"
                class="text-sm text-body underline hover:text-ink"
                @click="ticked = []"
            >
                Clear
            </button>

            <span class="text-xs text-muted">
                This replaces whoever holds them now.
            </span>
        </div>

        <p v-if="assignNotice" class="mb-4 rounded border border-line bg-sunk px-3 py-2 text-sm text-body" role="status">
            {{ assignNotice }}
        </p>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            The list could not be loaded. Please refresh.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <th v-if="canAssign" class="w-8 px-3 py-2">
                            <input
                                type="checkbox"
                                class="rounded border-line-strong"
                                :checked="allTicked"
                                @change="toggleAll"
                            />
                        </th>
                        <SortableHeader field="reference" :sort="activeSort" :direction="activeDirection" @sort="onSort">Reference</SortableHeader>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">About</th>
                        <th class="px-3 py-2 font-medium">Assigned to</th>
                        <SortableHeader field="due" :sort="activeSort" :direction="activeDirection" @sort="onSort">Due</SortableHeader>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending">
                        <td :colspan="canAssign ? 7 : 6" class="px-3 py-6 text-center text-muted">Loading…</td>
                    </tr>

                    <tr v-else-if="!rows.length">
                        <td :colspan="canAssign ? 7 : 6" class="px-3 py-6 text-center text-muted">
                            Nothing matches those filters.
                        </td>
                    </tr>

                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="cursor-pointer border-b border-line last:border-0 hover:bg-sunk"
                        @click="open(row)"
                    >
                        <td v-if="canAssign" class="px-3 py-2" @click.stop>
                            <input
                                v-model="ticked"
                                type="checkbox"
                                :value="row.id"
                                class="rounded border-line-strong"
                            />
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap font-medium tabular-nums text-ink">
                            {{ row.reference }}
                            <span v-if="row.reopened_count" class="ms-1 text-xs text-crit">
                                reopened ×{{ row.reopened_count }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.customer?.name ?? '—' }}
                            <div class="text-xs text-faint">{{ row.vehicle?.registration }}</div>
                        </td>
                        <td class="px-3 py-2 text-body">{{ row.category.label }}</td>
                        <td class="px-3 py-2 text-body">
                            {{ row.assignee?.name ?? 'Nobody yet' }}
                        </td>
                        <td
                            class="px-3 py-2 whitespace-nowrap tabular-nums"
                            :class="row.is_overdue ? 'font-medium text-crit' : 'text-body'"
                        >
                            {{ due(row) }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass(row)">
                                {{ statusLabel(row) }}
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

        <!-- The one being read, with its whole trail. -->
        <div
            v-if="openId"
            class="fixed inset-0 z-20 flex justify-end bg-accent/30"
            @click.self="openId = null"
        >
            <aside class="h-full w-full max-w-lg overflow-y-auto bg-surface p-5 shadow-xl">
                <div v-if="detail.data.value" class="space-y-4">
                    <header class="flex items-start gap-3">
                        <div>
                            <h2 class="text-lg font-semibold tabular-nums text-ink">
                                {{ detail.data.value.data.reference }}
                            </h2>
                            <p class="text-sm text-muted">
                                {{ detail.data.value.data.category.label }} ·
                                {{ detail.data.value.data.customer?.name }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="ms-auto rounded px-2 py-1 text-sm text-muted hover:bg-ground"
                            @click="openId = null"
                        >
                            Close
                        </button>
                    </header>

                    <p class="rounded bg-sunk p-3 text-sm text-body">
                        {{ detail.data.value.data.description }}
                    </p>

                    <p v-if="actionError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                        {{ actionError }}
                    </p>

                    <section>
                        <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">
                            What has happened
                        </h3>
                        <ol class="space-y-2 border-s border-line ps-4">
                            <li v-for="event in detail.data.value.data.events ?? []" :key="event.id" class="text-sm">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-medium text-body">{{ event.type }}</span>
                                    <span class="text-xs text-faint">
                                        {{ event.actor?.name ?? 'system' }} ·
                                        {{ event.created_at ? new Date(event.created_at).toLocaleString('en-IN') : '' }}
                                    </span>
                                </div>
                                <p v-if="event.note" class="text-body">{{ event.note }}</p>
                            </li>
                        </ol>
                    </section>

                    <section class="space-y-2">
                        <textarea
                            v-model="noteText"
                            rows="3"
                            placeholder="Add a note, or explain a resolution"
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        ></textarea>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded border border-line-strong px-3 py-1.5 text-sm hover:bg-sunk disabled:opacity-50"
                                :disabled="!noteText || move.isPending.value"
                                @click="move.mutate({ id: openId!, action: 'notes', body: { note: noteText } })"
                            >
                                Add note
                            </button>

                            <button
                                v-if="auth.can('resolve.complaint')"
                                type="button"
                                class="rounded bg-ok px-3 py-1.5 text-sm font-medium text-on-accent hover:brightness-110 disabled:opacity-50"
                                :disabled="!noteText || move.isPending.value"
                                @click="move.mutate({ id: openId!, action: 'resolve', body: { resolution: noteText } })"
                            >
                                Mark resolved
                            </button>

                            <button
                                v-if="auth.can('close.complaint')"
                                type="button"
                                class="rounded border border-line-strong px-3 py-1.5 text-sm hover:bg-sunk disabled:opacity-50"
                                :disabled="move.isPending.value"
                                @click="move.mutate({ id: openId!, action: 'close', body: { note: noteText || null } })"
                            >
                                Close
                            </button>

                            <button
                                type="button"
                                class="rounded border border-crit px-3 py-1.5 text-sm text-crit hover:bg-crit-soft disabled:opacity-50"
                                :disabled="!noteText || move.isPending.value"
                                @click="move.mutate({ id: openId!, action: 'reopen', body: { reason: noteText } })"
                            >
                                Reopen
                            </button>
                        </div>
                    </section>
                </div>

                <p v-else class="text-sm text-muted">Loading…</p>
            </aside>
        </div>
    </div>
</template>
