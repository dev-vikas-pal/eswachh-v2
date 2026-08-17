<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';

/**
 * The application log, a day at a time.
 *
 * v1 had this and it earns its place: when something goes wrong on a live
 * server, the person who can help is rarely the person with shell access.
 *
 * Only the last ten days exist to read - older files are deleted as they are
 * written, so this screen can never show more than the system keeps.
 */
interface LogDay { date: string; size: string; bytes: number; modified: string }
interface LogEntry { at: string; level: string; environment: string; message: string; context: string }

const queryClient = useQueryClient();

const level = ref('');
const search = ref('');
const expanded = ref<number | null>(null);

const { data: days, isPending: loadingDays, error: daysError } = useQuery({
    queryKey: ['logs'],
    queryFn: async (): Promise<{ data: LogDay[]; meta: { kept_days: number; note: string } }> =>
        (await api.get('/logs')).data,
});

const chosen = ref<string | null>(null);

// Land on the newest day rather than an empty screen.
watch(days, (next) => {
    if (!chosen.value && next?.data.length) chosen.value = next.data[0].date;
}, { immediate: true });

const { data: entries, isFetching } = useQuery({
    queryKey: computed(() => ['logs', chosen.value, level.value, search.value]),
    enabled: computed(() => !!chosen.value),
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<{
        data: LogEntry[];
        meta: { date: string; total: number; shown: number; levels: Record<string, number> };
    }> => (await api.get(`/logs/${chosen.value}`, {
        params: { level: level.value || undefined, search: search.value || undefined },
    })).data,
});

const rows = computed(() => entries.value?.data ?? []);
const meta = computed(() => entries.value?.meta);

const clearing = ref(false);

/**
 * Empty the day being read.
 *
 * Asked first, and said plainly: a log is the only account of what happened on
 * a server, and there is no undo. Useful when one noisy job has buried
 * everything else and the next thing to debug needs a clean page.
 */
async function clearDay(): Promise<void> {
    if (!chosen.value) return;

    if (!confirm(`Empty the log for ${chosen.value}? There is no way to get it back.`)) return;

    clearing.value = true;

    try {
        await api.delete(`/logs/${chosen.value}`);
        await queryClient.invalidateQueries({ queryKey: ['logs'] });
    } catch (e) {
        alert(describeError(e).message);
    } finally {
        clearing.value = false;
    }
}

/** Only the levels worth acting on get a colour; the rest stay quiet. */
function tone(entry: LogEntry): string {
    switch (entry.level) {
        case 'EMERGENCY':
        case 'ALERT':
        case 'CRITICAL':
        case 'ERROR':
            return 'bg-crit-soft text-crit';
        case 'WARNING':
            return 'bg-warn-soft text-warn';
        case 'NOTICE':
        case 'INFO':
            return 'bg-info-soft text-info';
        default:
            return 'bg-sunk text-muted';
    }
}

function at(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Logs</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>
        </div>

        <p v-if="daysError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            {{ describeError(daysError).message }}
        </p>

        <p v-else-if="loadingDays" class="text-muted">Loading…</p>

        <p v-else-if="!days!.data.length" class="rounded-lg border border-line bg-surface px-4 py-8 text-center text-muted">
            Nothing has been logged yet.
        </p>

        <template v-else>
            <p class="mb-3 text-sm text-muted">{{ days!.meta.note }}</p>

            <!-- One button per day, newest first. -->
            <div class="mb-4 flex flex-wrap gap-2">
                <button
                    v-for="day in days!.data"
                    :key="day.date"
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm transition"
                    :class="chosen === day.date
                        ? 'border-accent bg-accent-soft font-medium text-accent-ink'
                        : 'border-line-strong text-body hover:bg-sunk'"
                    @click="chosen = day.date; expanded = null"
                >
                    {{ day.date }}
                    <span class="ms-1 text-xs text-faint">{{ day.size }}</span>
                </button>
            </div>

            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
                <label>
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                    <input v-model.trim="search" type="search" placeholder="Message or trace" class="w-full rounded border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink sm:w-72 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                </label>

                <label>
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Level</span>
                    <select v-model="level" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="">All</option>
                        <option v-for="(count, name) in meta?.levels ?? {}" :key="name" :value="name">
                            {{ name }} ({{ count }})
                        </option>
                    </select>
                </label>

                <p v-if="meta" class="ms-auto text-sm text-muted">
                    Showing <strong class="text-ink">{{ meta.shown }}</strong> of {{ meta.total }}
                </p>

                <button
                    v-if="chosen"
                    type="button"
                    class="rounded border border-crit px-3 py-1.5 text-sm font-medium text-crit transition hover:bg-crit-soft disabled:opacity-60"
                    :disabled="clearing"
                    @click="clearDay"
                >
                    {{ clearing ? 'Emptying…' : 'Empty this day' }}
                </button>
            </div>

            <p v-if="!rows.length" class="rounded-lg border border-line bg-surface px-4 py-8 text-center text-muted">
                Nothing on this day matches.
            </p>

            <ol v-else class="flex flex-col gap-1.5">
                <li
                    v-for="(entry, i) in rows"
                    :key="i"
                    class="rounded border border-line bg-surface p-3"
                >
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="rounded px-2 py-0.5 text-xs font-medium" :class="tone(entry)">{{ entry.level }}</span>
                        <span class="text-xs tabular-nums text-muted">{{ at(entry.at) }}</span>
                        <span class="text-xs text-faint">{{ entry.environment }}</span>
                    </div>

                    <p class="mt-1.5 break-words text-sm text-body">{{ entry.message }}</p>

                    <!-- A trace is folded away: it is a hundred lines and only
                         matters once somebody has decided this is the entry. -->
                    <template v-if="entry.context">
                        <button
                            type="button"
                            class="mt-1.5 text-xs text-accent underline"
                            @click="expanded = expanded === i ? null : i"
                        >
                            {{ expanded === i ? 'Hide detail' : 'Show detail' }}
                        </button>

                        <pre
                            v-if="expanded === i"
                            class="mt-2 max-h-80 overflow-auto rounded bg-sunk p-3 text-xs leading-relaxed text-body"
                        >{{ entry.context }}</pre>
                    </template>
                </li>
            </ol>
        </template>
    </div>
</template>
