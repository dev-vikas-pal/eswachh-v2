<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';

/**
 * People who reached the payment page and stopped.
 *
 * The signup flow writes the customer, the car and the plan before the payment
 * window opens, so somebody who closes it leaves a record. Nothing read those
 * records until now - they sat as pending plans nobody looked at.
 *
 * Everyone here wanted the service, gave their number, and got as far as
 * paying. A phone call is usually all it takes.
 */
interface AbandonedRow {
    id: string;
    name: string | null;
    phone: string | null;
    sector: string | null;
    car: string | null;
    amount: number;
    status: string;
    started_at: string;
    subscription_id: string | null;
}

const auth = useAuthStore();

const days = ref(30);
const page = ref(1);

watch(days, () => { page.value = 1; });

const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: computed(() => ['abandoned', days.value, page.value, auth.selectedSectorId]),
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get('/abandoned-signups', {
        params: {
            days: days.value,
            page: page.value,
            sector_id: auth.selectedSectorId || undefined,
        },
    })).data,
});

const rows = computed<AbandonedRow[]>(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

const money = (n: number) => `₹${n.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;

/**
 * How long ago they gave up.
 *
 * "Two hours ago" and "three weeks ago" are different calls, and the second is
 * usually not worth making - so the age is on the row rather than a date the
 * reader has to do arithmetic on.
 */
function age(iso: string): string {
    const hours = Math.round((Date.now() - new Date(iso).getTime()) / 36e5);

    if (hours < 1) return 'just now';
    if (hours < 24) return `${hours}h ago`;

    const d = Math.round(hours / 24);
    return `${d}d ago`;
}

/** Fresh enough that they may still be at their desk. */
const isWarm = (iso: string) => Date.now() - new Date(iso).getTime() < 48 * 36e5;
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Did not finish paying</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>

            <label class="ms-auto flex items-center gap-2">
                <span class="text-xs font-medium uppercase tracking-wide text-muted">Last</span>
                <select
                    v-model.number="days"
                    class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option :value="2">2 days</option>
                    <option :value="7">7 days</option>
                    <option :value="30">30 days</option>
                    <option :value="90">90 days</option>
                </select>
            </label>
        </div>

        <p class="mb-4 text-sm text-muted">
            Everybody here gave us their number and got as far as the payment page. Their details
            are already saved — a call is usually all it takes.
        </p>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            {{ describeError(error).message }}
        </p>

        <p v-else-if="isPending" class="text-muted">Loading…</p>

        <p v-else-if="!rows.length" class="rounded-lg border border-line bg-surface px-4 py-8 text-center text-muted">
            Nobody has abandoned a signup in this period. Good.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Who</th>
                        <th class="px-3 py-2 font-medium">Call</th>
                        <th class="px-3 py-2 font-medium">Car</th>
                        <th class="px-3 py-2 font-medium">Sector</th>
                        <th class="px-3 py-2 text-right font-medium">Amount</th>
                        <th class="px-3 py-2 font-medium">Gave up</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-b border-line last:border-0">
                        <td class="px-3 py-2 font-medium text-ink">{{ row.name ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <!-- A link, not text: on a phone this is the whole job. -->
                            <a
                                v-if="row.phone"
                                :href="`tel:${row.phone}`"
                                class="font-medium text-accent hover:underline"
                            >{{ row.phone }}</a>
                            <span v-else class="text-muted">no number</span>
                        </td>
                        <td class="px-3 py-2 tabular-nums text-body">{{ row.car ?? '—' }}</td>
                        <td class="px-3 py-2 text-body">{{ row.sector ?? '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-body">{{ money(row.amount) }}</td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded px-2 py-0.5 text-xs"
                                :class="isWarm(row.started_at) ? 'bg-ok-soft text-ok' : 'bg-sunk text-muted'"
                            >{{ age(row.started_at) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center gap-2">
            <button
                type="button"
                class="rounded border border-line-strong px-3 py-1.5 text-sm text-body disabled:opacity-50"
                :disabled="page <= 1"
                @click="page--"
            >
                Back
            </button>
            <span class="text-sm text-muted">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button
                type="button"
                class="rounded border border-line-strong px-3 py-1.5 text-sm text-body disabled:opacity-50"
                :disabled="page >= meta.last_page"
                @click="page++"
            >
                Next
            </button>
        </div>
    </div>
</template>
