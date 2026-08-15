<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import DateRangeFilter from '@/shared/DateRangeFilter.vue';
import type { DashboardData } from '@/shared/types';

const auth = useAuthStore();

const from = ref('');
const to = ref('');

/**
 * The date range applies to the money block only.
 *
 * Everything in the tiles is a count of how things stand right now - "how many
 * plans were active last Tuesday" is not a question the data can answer - so
 * pretending the whole screen responds to a date would be a lie told in the
 * interface.
 */
function onPeriod(range: { from: string; to: string }) {
    from.value = range.from;
    to.value = range.to;
}

/**
 * One request for the whole screen. The query key includes the branch, so
 * switching branch refetches rather than showing the previous one's numbers.
 */
const { data, isPending, isError } = useQuery({
    queryKey: computed(() => ['dashboard', auth.selectedBranchId, from.value, to.value]),
    queryFn: async (): Promise<DashboardData> => {
        const { data } = await api.get('/dashboard', {
            params: {
                ...(auth.selectedBranchId ? { branch_id: auth.selectedBranchId } : {}),
                from: from.value || undefined,
                to: to.value || undefined,
            },
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

/** Rupees, rounded: the dashboard is for a glance, not for reconciling. */
function money(paise: number): string {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency', currency: 'INR', maximumFractionDigits: 0,
    }).format((paise ?? 0) / 100);
}

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

        <!-- v-else-if rather than v-else so the template narrows `data`. -->
        <div v-else-if="data" class="flex flex-col gap-5">
            <!-- What happened in a window, as opposed to how things stand. -->
            <section class="rounded-lg border border-line bg-surface p-4">
                <div class="mb-3 flex flex-wrap items-end gap-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">In this period</h2>
                    <DateRangeFilter initial="this_month" label="" @change="onPeriod" />
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ money(data.period.revenue_paise) }}</p>
                        <p class="text-sm text-body">Taken</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ data.period.payments }}</p>
                        <p class="text-sm text-body">Payments</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ data.period.new_plans }}</p>
                        <p class="text-sm text-body">New plans</p>
                    </div>
                </div>
            </section>

            <!-- Everything below is as things stand today, whatever the range. -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">
                    As things stand
                </h2>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
            </section>
        </div>
    </div>
</template>
