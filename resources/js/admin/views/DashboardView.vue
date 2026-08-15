<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import type { DashboardData } from '@/shared/types';

const auth = useAuthStore();

/**
 * One request for the whole screen. The query key includes the branch, so
 * switching branch refetches rather than showing the previous one's numbers.
 */
const { data, isPending, isError } = useQuery({
    queryKey: computed(() => ['dashboard', auth.selectedBranchId]),
    queryFn: async (): Promise<DashboardData> => {
        const { data } = await api.get('/dashboard', {
            params: auth.selectedBranchId ? { branch_id: auth.selectedBranchId } : {},
        });
        return data.data;
    },
});

/**
 * Expired is a subset of active, not a separate group. Saying so on the tile
 * saves the question being asked in every meeting.
 */
const tiles = computed(() => {
    if (!data.value) return [];

    const s = data.value.subscriptions;

    return [
        { label: 'Subscriptions', value: s.active, note: 'active, including overdue', tone: 'plain' },
        { label: 'In date', value: s.current, note: 'not yet due for renewal', tone: 'good' },
        { label: 'Expired', value: s.expired, note: 'past renewal, still running', tone: 'warn' },
        { label: 'On hold', value: s.hold, note: 'paused', tone: 'bad' },
        { label: 'No cleaner', value: s.unassigned, note: 'active but unassigned', tone: 'warn' },
        { label: 'Customers', value: data.value.people.customers, note: '', tone: 'plain' },
        { label: 'Cleaners', value: data.value.people.cleaners, note: '', tone: 'plain' },
        { label: 'Vehicles', value: data.value.vehicles.total, note: '', tone: 'plain' },
    ];
});

const toneClass: Record<string, string> = {
    plain: 'text-ink',
    good: 'text-ok',
    warn: 'text-warn',
    bad: 'text-crit',
};
</script>

<template>
    <div>
        <h1 class="mb-5 text-xl font-semibold tracking-tight text-ink">Dashboard</h1>

        <p v-if="isPending" class="text-sm text-muted">Loading…</p>

        <p v-else-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            The dashboard could not be loaded. Please refresh.
        </p>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="rounded-lg border border-line bg-surface p-4"
            >
                <div class="text-2xl font-semibold tabular-nums" :class="toneClass[tile.tone]">
                    {{ tile.value }}
                </div>
                <div class="mt-0.5 text-sm font-medium text-body">{{ tile.label }}</div>
                <div v-if="tile.note" class="mt-0.5 text-xs text-muted">{{ tile.note }}</div>
            </div>
        </div>
    </div>
</template>
