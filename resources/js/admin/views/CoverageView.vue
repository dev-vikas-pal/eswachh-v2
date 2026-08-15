<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import type { Coverage, CoverageRow } from '@/shared/types';

const auth = useAuthStore();

const date = ref(new Date().toISOString().slice(0, 10));

const { data, isPending, isError, isFetching } = useQuery({
    queryKey: computed(() => ['coverage', auth.selectedBranchId, date.value]),
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<{ data: Coverage }> => {
        const { data } = await api.get('/attendance/coverage', { params: { date: date.value } });
        return data;
    },
});

const coverage = computed(() => data.value?.data);
const rows = computed(() => coverage.value?.cleaners ?? []);

/**
 * Every figure here is counted from service logs. There is nowhere on this
 * screen to type a number in, which is the point: v1's daily figures were
 * whatever the office entered.
 */
const totals = computed(() => coverage.value?.totals);

function attendanceClass(row: CoverageRow): string {
    if (row.unmarked) return 'bg-ground text-muted';
    switch (row.attendance) {
        case 'present':
            return 'bg-ok-soft text-ok';
        case 'absent':
            return 'bg-crit-soft text-crit';
        default:
            return 'bg-sky-50 text-sky-700';
    }
}

function progressClass(row: CoverageRow): string {
    if (row.due === 0) return 'text-faint';
    if (row.cleaned === row.due) return 'text-ok';
    if (row.failed > 0 || row.unaccounted > 0) return 'text-warn';
    return 'text-body';
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-baseline gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Daily coverage</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>

            <label class="ms-auto flex items-center gap-2 text-sm text-body">
                <span>Day</span>
                <input
                    v-model="date"
                    type="date"
                    class="rounded border border-line-strong px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>
        </div>

        <div v-if="totals" class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-line bg-surface p-3">
                <div class="text-xs uppercase tracking-wide text-muted">Due</div>
                <div class="text-2xl font-semibold tabular-nums text-ink">{{ totals.due }}</div>
            </div>
            <div class="rounded-lg border border-line bg-surface p-3">
                <div class="text-xs uppercase tracking-wide text-muted">Cleaned</div>
                <div class="text-2xl font-semibold tabular-nums text-ok">{{ totals.cleaned }}</div>
            </div>
            <div class="rounded-lg border border-line bg-surface p-3">
                <!-- Cars we failed. A car the owner had driven to work is
                     counted separately, because it is not the same problem. -->
                <div class="text-xs uppercase tracking-wide text-muted">Failed</div>
                <div class="text-2xl font-semibold tabular-nums" :class="totals.failed ? 'text-crit' : 'text-faint'">
                    {{ totals.failed }}
                </div>
            </div>
            <div class="rounded-lg border border-line bg-surface p-3">
                <div class="text-xs uppercase tracking-wide text-muted">Unaccounted</div>
                <div class="text-2xl font-semibold tabular-nums" :class="totals.unaccounted ? 'text-warn' : 'text-faint'">
                    {{ totals.unaccounted }}
                </div>
            </div>
            <div class="rounded-lg border border-line bg-surface p-3">
                <div class="text-xs uppercase tracking-wide text-muted">Not marked</div>
                <div class="text-2xl font-semibold tabular-nums" :class="totals.unmarked_cleaners ? 'text-warn' : 'text-faint'">
                    {{ totals.unmarked_cleaners }}
                </div>
            </div>
        </div>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            Coverage could not be loaded. Please refresh.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <th class="px-3 py-2 font-medium">Cleaner</th>
                        <th class="px-3 py-2 font-medium">Attendance</th>
                        <th class="px-3 py-2 text-right font-medium">Due</th>
                        <th class="px-3 py-2 text-right font-medium">Cleaned</th>
                        <th class="px-3 py-2 text-right font-medium">Failed</th>
                        <th class="px-3 py-2 text-right font-medium">Not our fault</th>
                        <th class="px-3 py-2 text-right font-medium">Unaccounted</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending">
                        <td colspan="7" class="px-3 py-6 text-center text-muted">Loading…</td>
                    </tr>

                    <tr v-else-if="!rows.length">
                        <td colspan="7" class="px-3 py-6 text-center text-muted">
                            No cleaners on the books.
                        </td>
                    </tr>

                    <tr
                        v-for="row in rows"
                        :key="row.cleaner.id"
                        class="border-b border-line last:border-0 hover:bg-sunk"
                    >
                        <td class="px-3 py-2 font-medium text-ink">{{ row.cleaner.name }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="attendanceClass(row)">
                                {{ row.unmarked ? 'Not marked' : row.attendance_label }}
                            </span>
                            <!-- A week filled in on a Friday is not evidence of
                                 anything, so it says so. -->
                            <span v-if="row.marked_late" class="ms-1 text-xs text-faint">
                                entered later
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-body">{{ row.due }}</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums" :class="progressClass(row)">
                            {{ row.cleaned }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums" :class="row.failed ? 'text-crit' : 'text-faint'">
                            {{ row.failed }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-muted">{{ row.not_our_fault }}</td>
                        <td class="px-3 py-2 text-right tabular-nums" :class="row.unaccounted ? 'text-warn' : 'text-faint'">
                            {{ row.unaccounted }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
