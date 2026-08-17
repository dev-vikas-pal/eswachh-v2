<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import DateRangeFilter from '@/shared/DateRangeFilter.vue';

const auth = useAuthStore();

const selected = ref('revenue');
const from = ref('');
const to = ref('');

/**
 * The shared filter, so "last 3 months" here is the same three months it is on
 * Payments. Leaving it on "Any date" hands the server no bounds, and the server
 * then falls back to the financial year to date - which is the window these
 * questions are actually asked in.
 */
function onPeriod(range: { from: string; to: string }) {
    from.value = range.from;
    to.value = range.to;
}

const { data: catalogue } = useQuery({
    queryKey: ['reports'],
    queryFn: async () => (await api.get('/reports')).data.data,
    staleTime: Infinity,
});

const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: computed(() => ['report', selected.value, from.value, to.value, auth.selectedSectorId]),
    queryFn: async () => (await api.get(`/reports/${selected.value}`, {
        params: {
            from: from.value || undefined,
            to: to.value || undefined,
            // The picker in the top bar. Applied by narrowing the context, so
            // every figure on every report follows it.
            sector_id: auth.selectedSectorId || undefined,
        },
    })).data.data,
});

function money(paise: number): string {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency', currency: 'INR', maximumFractionDigits: 0,
    }).format((paise ?? 0) / 100);
}

function monthName(ym: string): string {
    const [y, m] = ym.split('-');
    return new Date(Number(y), Number(m) - 1).toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
}

/** The tallest bar sets the scale, so a quiet month is still visible. */
const peak = computed(() =>
    Math.max(1, ...(data.value?.months ?? []).map((m: { total_paise: number }) => m.total_paise)),
);

const renewalBuckets = [
    { key: 'overdue_30_plus', label: 'Overdue by a month or more', tone: 'crit' },
    { key: 'overdue_8_to_30', label: 'Overdue 8–30 days', tone: 'crit' },
    { key: 'overdue_1_to_7', label: 'Overdue this week', tone: 'warn' },
    { key: 'due_this_week', label: 'Due in the next 7 days', tone: 'warn' },
    { key: 'due_next_three_weeks', label: 'Due in 8–28 days', tone: 'ok' },
    { key: 'on_hold', label: 'Already paused', tone: 'muted' },
];

function toneClass(tone: string): string {
    return { crit: 'text-crit', warn: 'text-warn', ok: 'text-ok', muted: 'text-muted' }[tone] ?? 'text-ink';
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Reports</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>
        </div>

        <div class="flex flex-col gap-4 lg:flex-row">
            <nav class="flex shrink-0 flex-wrap gap-1 lg:w-56 lg:flex-col">
                <button
                    v-for="report in catalogue ?? []"
                    :key="report.key"
                    type="button"
                    class="rounded px-3 py-2 text-left text-sm transition"
                    :class="selected === report.key ? 'bg-accent-soft text-accent-ink' : 'text-body hover:bg-sunk hover:text-ink'"
                    @click="selected = report.key"
                >
                    <span class="block font-medium">{{ report.label }}</span>
                    <span class="hidden text-xs text-muted lg:block">{{ report.description }}</span>
                </button>
            </nav>

            <div class="min-w-0 flex-1">
                <div v-if="selected !== 'renewals'" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
                    <DateRangeFilter label="Period" @change="onPeriod" />

                    <p v-if="!from" class="pb-1.5 text-xs text-muted">
                        Showing this financial year so far.
                    </p>
                </div>

                <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                    {{ describeError(error).message }}
                </p>

                <p v-else-if="isPending" class="rounded border border-line bg-surface px-4 py-8 text-center text-muted">
                    Loading…
                </p>

                <!-- Revenue -->
                <div v-else-if="selected === 'revenue'" class="flex flex-col gap-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ink">{{ money(data.total_paise) }}</p>
                            <p class="text-xs text-muted">taken in this period</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ink">{{ data.payments }}</p>
                            <p class="text-xs text-muted">payments</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums" :class="data.abandoned > 0 ? 'text-warn' : 'text-ink'">
                                {{ data.abandoned }}
                            </p>
                            <!-- Reported beside the money on purpose: a rising
                                 number here means the payment page is broken. -->
                            <p class="text-xs text-muted">checkouts abandoned</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-line bg-surface p-4">
                        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">By month</h2>
                        <p v-if="!data.months.length" class="text-sm text-muted">Nothing was taken in this period.</p>
                        <ul v-else class="flex flex-col gap-2">
                            <li v-for="m in data.months" :key="m.month" class="flex items-center gap-3">
                                <span class="w-24 shrink-0 text-sm text-body">{{ monthName(m.month) }}</span>
                                <span class="h-5 rounded-sm bg-accent" :style="{ width: Math.max(2, (m.total_paise / peak) * 100) + '%' }" />
                                <span class="ms-auto shrink-0 text-sm font-medium tabular-nums text-ink">{{ money(m.total_paise) }}</span>
                            </li>
                        </ul>
                        <p v-if="data.recorded_by_hand_paise > 0" class="mt-3 border-t border-line pt-3 text-xs text-muted">
                            {{ money(data.recorded_by_hand_paise) }} of this was entered by hand rather than taken online.
                        </p>
                    </div>
                </div>

                <!-- Renewals -->
                <div v-else-if="selected === 'renewals'" class="rounded-lg border border-line bg-surface">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                                <th class="px-4 py-2 font-medium">Position</th>
                                <th class="px-4 py-2 text-right font-medium">Plans</th>
                                <th class="px-4 py-2 text-right font-medium">Worth</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in renewalBuckets" :key="b.key" class="border-b border-line last:border-0">
                                <td class="px-4 py-2.5" :class="toneClass(b.tone)">{{ b.label }}</td>
                                <td class="px-4 py-2.5 text-right font-medium tabular-nums text-ink">{{ data[b.key]?.count ?? 0 }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-body">{{ money(data[b.key]?.value_paise ?? 0) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="border-t border-line px-4 py-2 text-xs text-muted">
                        As at {{ data.as_at }}. Worth is what renewing all of them would bring in.
                    </p>
                </div>

                <!-- Service delivery -->
                <div v-else-if="selected === 'service'" class="flex flex-col gap-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ok">{{ data.cleaned }}</p>
                            <p class="text-xs text-muted">cars cleaned</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums" :class="data.our_failures > 0 ? 'text-crit' : 'text-ink'">
                                {{ data.our_failures }}
                            </p>
                            <p class="text-xs text-muted">we failed to reach</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-muted">{{ data.not_our_fault }}</p>
                            <!-- A car the owner had driven to work is not a
                                 service failure, so it is counted separately. -->
                            <p class="text-xs text-muted">not our fault</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-line bg-surface p-4">
                        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">What happened</h2>
                        <ul class="flex flex-col gap-1">
                            <li v-for="o in data.by_outcome" :key="o.outcome" class="flex justify-between text-sm">
                                <span class="text-body">{{ o.label }}</span>
                                <span class="font-medium tabular-nums text-ink">{{ o.count }}</span>
                            </li>
                        </ul>
                    </div>

                    <div v-if="data.busiest_cleaners.length" class="rounded-lg border border-line bg-surface p-4">
                        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">Most cars cleaned</h2>
                        <ul class="flex flex-col gap-1">
                            <li v-for="c in data.busiest_cleaners" :key="c.cleaner" class="flex justify-between text-sm">
                                <span class="text-body">{{ c.cleaner }}</span>
                                <span class="font-medium tabular-nums text-ink">{{ c.cleaned }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Complaints -->
                <div v-else-if="selected === 'complaints'" class="flex flex-col gap-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ink">{{ data.raised }}</p>
                            <p class="text-xs text-muted">raised</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ok">{{ data.answered_in_time }}</p>
                            <p class="text-xs text-muted">answered in time</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums" :class="data.answered_late > 0 ? 'text-warn' : 'text-ink'">
                                {{ data.answered_late }}
                            </p>
                            <p class="text-xs text-muted">answered late</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums" :class="data.overdue_now > 0 ? 'text-crit' : 'text-ink'">
                                {{ data.overdue_now }}
                            </p>
                            <p class="text-xs text-muted">overdue right now</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-line bg-surface p-4">
                        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">What people complain about</h2>
                        <p v-if="!data.by_category.length" class="text-sm text-muted">No complaints in this period.</p>
                        <ul v-else class="flex flex-col gap-1">
                            <li v-for="c in data.by_category" :key="c.category" class="flex justify-between text-sm">
                                <span class="text-body">{{ c.label }}</span>
                                <span class="font-medium tabular-nums text-ink">{{ c.count }}</span>
                            </li>
                        </ul>
                        <p v-if="data.reopened > 0" class="mt-3 border-t border-line pt-3 text-xs text-warn">
                            {{ data.reopened }} came back unsatisfied after being resolved.
                        </p>
                    </div>
                </div>

                <!-- Cloth -->
                <div v-else-if="selected === 'cloth'" class="flex flex-col gap-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ink">{{ data.purchased }}</p>
                            <p class="text-xs text-muted">bought</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-ink">{{ data.used }}</p>
                            <p class="text-xs text-muted">used</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-muted">{{ data.written_off }}</p>
                            <p class="text-xs text-muted">written off</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface p-4">
                            <p class="text-2xl font-bold tabular-nums text-warn">{{ data.outstanding.cloths }}</p>
                            <!-- Paid for and not yet had: a liability, not stock. -->
                            <p class="text-xs text-muted">still owed to customers</p>
                        </div>
                    </div>

                    <p class="rounded-lg border border-line bg-surface p-4 text-sm text-body">
                        {{ data.outstanding.subscriptions }} plan(s) still carry a cloth balance,
                        <template v-if="data.running_low > 0">
                            and <strong class="text-warn">{{ data.running_low }}</strong> of those are down to ten or fewer.
                        </template>
                        <template v-else>none of them running low.</template>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
