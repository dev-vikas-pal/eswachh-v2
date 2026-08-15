<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { api } from '@/shared/api/client';

/**
 * Things needing attention.
 *
 * Read is per person and resolved is for everybody, which is why they are two
 * different buttons: marking something read for yourself must not clear it for
 * whoever actually has to act on it.
 */
const queryClient = useQueryClient();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const { data } = useQuery({
    queryKey: ['alerts'],
    queryFn: async () => (await api.get('/alerts')).data,
    // Checked periodically rather than on every navigation: an alert raised by
    // a nightly job does not need to appear within the second.
    refetchInterval: 120_000,
});

const alerts = computed(() => data.value?.data ?? []);
const unread = computed<number>(() => data.value?.meta?.unread ?? 0);
const critical = computed<number>(() => data.value?.meta?.critical ?? 0);

function toneClass(severity: string): string {
    return {
        critical: 'border-l-crit bg-crit-soft',
        warning: 'border-l-warn bg-warn-soft',
    }[severity] ?? 'border-l-line-strong bg-sunk';
}

async function markRead(id: string) {
    await api.post(`/alerts/${id}/read`);
    await queryClient.invalidateQueries({ queryKey: ['alerts'] });
}

async function markAllRead() {
    await api.post('/alerts/read-all');
    await queryClient.invalidateQueries({ queryKey: ['alerts'] });
}

async function resolve(id: string) {
    await api.post(`/alerts/${id}/resolve`);
    await queryClient.invalidateQueries({ queryKey: ['alerts'] });
}

function onPointer(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

function onKey(event: KeyboardEvent) {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onPointer);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative rounded border border-line-strong px-2.5 py-1.5 text-sm text-body transition hover:bg-sunk focus:outline-none focus:ring-2 focus:ring-accent"
            :aria-expanded="open"
            :title="unread ? unread + ' needing attention' : 'Nothing needs attention'"
            @click="open = !open"
        >
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path d="M5.5 8a4.5 4.5 0 019 0c0 3.5 1.5 4.5 1.5 4.5H4S5.5 11.5 5.5 8zM8.5 15.5a1.5 1.5 0 003 0" stroke-linecap="round" stroke-linejoin="round" />
            </svg>

            <span
                v-if="unread"
                class="absolute -right-1.5 -top-1.5 min-w-4 rounded-full px-1 text-[10px] font-bold leading-4 text-on-accent"
                :class="critical ? 'bg-crit' : 'bg-accent'"
            >
                {{ unread > 9 ? '9+' : unread }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-30 mt-2 max-h-96 w-80 overflow-y-auto rounded-lg border border-line-strong bg-surface p-2 shadow-lg"
        >
            <div class="flex items-center gap-2 px-2 pb-2">
                <span class="text-xs font-medium uppercase tracking-wide text-muted">Needs attention</span>
                <button
                    v-if="unread"
                    type="button"
                    class="ms-auto text-xs text-accent-ink hover:underline"
                    @click="markAllRead"
                >
                    Mark all read
                </button>
            </div>

            <p v-if="!alerts.length" class="px-2 py-6 text-center text-sm text-muted">
                Nothing outstanding.
            </p>

            <article
                v-for="alert in alerts"
                :key="alert.id"
                class="mb-1.5 rounded border-l-4 p-3 last:mb-0"
                :class="[toneClass(alert.severity), alert.read ? 'opacity-60' : '']"
            >
                <h3 class="text-sm font-semibold text-ink">{{ alert.title }}</h3>
                <p v-if="alert.body" class="mt-0.5 text-xs text-body">{{ alert.body }}</p>

                <div class="mt-2 flex items-center gap-3 text-xs">
                    <RouterLink
                        v-if="alert.link"
                        :to="{ name: alert.link.route, params: alert.link.params ?? {} }"
                        class="font-medium text-accent-ink hover:underline"
                        @click="open = false"
                    >
                        Go there
                    </RouterLink>

                    <button v-if="!alert.read" type="button" class="text-muted hover:text-ink" @click="markRead(alert.id)">
                        Mark read
                    </button>

                    <!-- Closes it for everyone, which is why it says so. -->
                    <button type="button" class="ms-auto text-muted hover:text-ink" @click="resolve(alert.id)" title="Closes it for everybody">
                        Dealt with
                    </button>
                </div>
            </article>
        </div>
    </div>
</template>
