<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
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
    queryKey: computed(() => ['dashboard', auth.selectedSectorId, from.value, to.value]),
    queryFn: async (): Promise<DashboardData> => {
        const { data } = await api.get('/dashboard', {
            params: {
                ...(auth.selectedSectorId ? { sector_id: auth.selectedSectorId } : {}),
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
/**
 * Each tile opens the rows behind it.
 *
 * A count somebody cannot act on is trivia. "On hold: 4" is only useful if the
 * next click is those four plans, and the alternative - reading the number, then
 * going to Subscriptions and rebuilding the same filter by hand - is exactly the
 * kind of work software is meant to remove.
 *
 * Every link narrows to the same figure the tile shows, which is why "In date"
 * needed a filter of its own on the server. A tile that opens a different number
 * from the one it displays is worse than a tile that does not open at all.
 */
const tiles = computed(() => {
    if (!data.value) return [];

    const s = data.value.subscriptions;

    return [
        {
            label: 'Subscriptions', value: s.active, note: 'active, including overdue', tone: 'plain',
            to: { name: 'subscriptions', query: { status: 'active' } },
        },
        {
            label: 'In date', value: s.current, note: 'not yet due for renewal', tone: 'good',
            to: { name: 'subscriptions', query: { current: '1' } },
        },
        {
            label: 'Expired', value: s.expired, note: 'past renewal, still running', tone: 'warn',
            to: { name: 'subscriptions', query: { expired: '1' } },
        },
        {
            label: 'On hold', value: s.hold, note: 'paused', tone: 'bad',
            to: { name: 'subscriptions', query: { status: 'hold' } },
        },
        {
            label: 'No cleaner', value: s.unassigned, note: 'active but unassigned', tone: 'warn',
            to: { name: 'subscriptions', query: { unassigned: '1' } },
        },
        {
            label: 'Customers', value: data.value.people.customers, note: '', tone: 'plain',
            to: { name: 'customers' },
        },
        {
            label: 'Cleaners', value: data.value.people.cleaners, note: '', tone: 'plain',
            to: auth.can('view.staff') ? { name: 'users', query: { role: 'cleaner' } } : null,
        },
        /*
         * Cars live under the customer who owns them, so this opens the people
         * rather than a list of registrations - which is what somebody looking
         * at the number actually wants next.
         */
        {
            label: 'Vehicles', value: data.value.vehicles.total, note: '', tone: 'plain',
            to: { name: 'customers' },
        },
    ];
});

/**
 * The money block opens the payments it counted, over the same dates.
 *
 * Null while the range is still being resolved, so the link can never carry a
 * half-set period and show a different total from the one above it.
 */
const paymentsLink = computed(() => ({
    name: 'payments',
    query: {
        status: 'captured',
        ...(from.value ? { from: from.value } : {}),
        ...(to.value ? { to: to.value } : {}),
    },
}));

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
                    <!--
                        Both money figures open the same list, over the same
                        dates: they are two readings of one set of payments, and
                        sending them to different places would suggest otherwise.
                    -->
                    <RouterLink
                        v-if="auth.can('view.payment')"
                        :to="paymentsLink"
                        class="group rounded transition hover:bg-sunk focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ money(data.period.revenue_paise) }}</p>
                        <p class="text-sm text-body group-hover:text-ink">Taken</p>
                    </RouterLink>
                    <div v-else>
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ money(data.period.revenue_paise) }}</p>
                        <p class="text-sm text-body">Taken</p>
                    </div>

                    <RouterLink
                        v-if="auth.can('view.payment')"
                        :to="paymentsLink"
                        class="group rounded transition hover:bg-sunk focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ data.period.payments }}</p>
                        <p class="text-sm text-body group-hover:text-ink">Payments</p>
                    </RouterLink>
                    <div v-else>
                        <p class="text-2xl font-semibold tabular-nums text-ink">{{ data.period.payments }}</p>
                        <p class="text-sm text-body">Payments</p>
                    </div>

                    <!--
                        Not a link: there is no "created between these dates"
                        filter on the plan list, and sending somebody to an
                        unfiltered list from a figure for one month would be
                        worse than leaving the number as a number.
                    -->
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
                    <!--
                        A link where there is somewhere to go, a plain card where
                        there is not - so nothing looks clickable and then isn't,
                        which is how a person learns to stop trying.
                    -->
                    <component
                        :is="tile.to ? 'RouterLink' : 'div'"
                        v-for="tile in tiles"
                        :key="tile.label"
                        :to="tile.to ?? undefined"
                        class="group rounded-lg border border-line bg-surface p-4 transition"
                        :class="tile.to
                            ? 'cursor-pointer hover:border-accent hover:bg-sunk focus:outline-none focus-visible:ring-2 focus-visible:ring-accent'
                            : ''"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-2xl font-semibold tabular-nums" :class="toneClass[tile.tone]">
                                {{ tile.value }}
                            </div>

                            <svg
                                v-if="tile.to"
                                class="mt-1 h-4 w-4 shrink-0 text-faint opacity-0 transition group-hover:opacity-100"
                                viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"
                            >
                                <path d="M7 4h9v9M16 4 4 16" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>

                        <div class="mt-0.5 text-sm font-medium text-body">{{ tile.label }}</div>
                        <div v-if="tile.note" class="mt-0.5 text-xs text-muted">{{ tile.note }}</div>
                    </component>
                </div>
            </section>
        </div>
    </div>
</template>
