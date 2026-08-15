<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';

const queryClient = useQueryClient();

const busy = ref(false);
const notice = ref<string | null>(null);
const error = ref<string | null>(null);

const { data, isPending } = useQuery({
    queryKey: ['backups'],
    queryFn: async () => (await api.get('/backups')).data,
});

const files = computed(() => data.value?.data ?? []);
const latest = computed<string | null>(() => data.value?.meta?.latest ?? null);

/**
 * How stale the newest backup is.
 *
 * The one number that matters on this screen: a list of files tells you
 * nothing, and a backup nobody has taken for a fortnight is not a backup.
 */
const hoursSinceLatest = computed<number | null>(() => {
    if (!latest.value) return null;
    return Math.floor((Date.now() - new Date(latest.value).getTime()) / 3_600_000);
});

const health = computed(() => {
    const hours = hoursSinceLatest.value;
    if (hours === null) return { tone: 'crit', text: 'No backup has ever been taken.' };
    if (hours > 48) return { tone: 'crit', text: `The last backup was ${Math.floor(hours / 24)} days ago.` };
    if (hours > 26) return { tone: 'warn', text: `The last backup was ${hours} hours ago.` };
    return { tone: 'ok', text: `Last backup ${hours < 1 ? 'less than an hour' : hours + ' hours'} ago.` };
});

async function takeOne() {
    busy.value = true;
    notice.value = null;
    error.value = null;

    try {
        const { data: result } = await api.post('/backups');
        notice.value = result.message;
        await queryClient.invalidateQueries({ queryKey: ['backups'] });
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}

async function remove(name: string) {
    if (!confirm(`Delete ${name}? This cannot be undone.`)) return;

    await api.delete(`/backups/${name}`);
    await queryClient.invalidateQueries({ queryKey: ['backups'] });
}

function when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <div class="max-w-3xl">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Backups</h1>

            <button
                type="button"
                :disabled="busy"
                class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-accent"
                @click="takeOne"
            >
                {{ busy ? 'Taking a copy…' : 'Back up now' }}
            </button>
        </div>

        <p
            class="mb-4 rounded border px-3 py-2 text-sm"
            :class="{
                ok: 'border-line bg-ok-soft text-ok',
                warn: 'border-line bg-warn-soft text-warn',
                crit: 'border-crit bg-crit-soft text-crit',
            }[health.tone]"
        >
            {{ health.text }}
            One is taken automatically every night at ten past midnight, before anything else runs.
        </p>

        <p v-if="notice" class="mb-3 rounded bg-ok-soft px-3 py-2 text-sm text-ok">{{ notice }}</p>
        <p v-if="error" class="mb-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ error }}</p>

        <div class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <th class="px-3 py-2 font-medium">Taken</th>
                        <th class="px-3 py-2 font-medium">File</th>
                        <th class="px-3 py-2 text-right font-medium">Size</th>
                        <th class="px-3 py-2 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending">
                        <td colspan="4" class="px-3 py-6 text-center text-muted">Loading…</td>
                    </tr>
                    <tr v-else-if="!files.length">
                        <td colspan="4" class="px-3 py-6 text-center text-muted">No backups yet.</td>
                    </tr>
                    <tr v-for="file in files" :key="file.name" class="border-b border-line last:border-0 hover:bg-sunk">
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums text-ink">{{ when(file.taken_at) }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-muted">{{ file.name }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-body">{{ file.size_human }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <!--
                                A plain link, not fetch: the browser handles the
                                download, and the route checks who is asking.
                            -->
                            <a
                                :href="'/api/v1/backups/' + file.name"
                                class="rounded px-2 py-1 text-xs font-medium text-accent-ink hover:bg-accent-soft"
                            >
                                Download
                            </a>
                            <button
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-crit hover:bg-crit-soft"
                                @click="remove(file.name)"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-muted">
            A backup holds every customer's details and every password hash. Keep the downloaded file
            somewhere you would keep a bank statement, and delete it when you are done with it.
        </p>
    </div>
</template>
