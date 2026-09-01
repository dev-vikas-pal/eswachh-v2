<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { refreshAfter } from '@/shared/api/refresh';

/**
 * Cloths: collecting them and giving them back.
 *
 * Two jobs on one screen because they are two halves of one round, and the
 * cleaner doing them is holding a phone in one hand. Collect is a car at a
 * time - they are standing next to it. Deliver is a list, because they come
 * back with a bag of everybody's and work down it, society by society.
 */
const queryClient = useQueryClient();

const tab = ref<'collect' | 'deliver'>('collect');

// ------------------------------------------------------------------ collect

const registration = ref('');
const found = ref<Record<string, unknown> | null>(null);
const count = ref(1);
const busy = ref(false);
const problem = ref<string | null>(null);
const notice = ref<string | null>(null);

async function lookup() {
    busy.value = true;
    problem.value = null;
    notice.value = null;
    found.value = null;

    try {
        const { data } = await api.post('/cloth/lookup', { registration: registration.value });
        found.value = data.data;
        count.value = 1;
    } catch (e) {
        problem.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}

async function collect() {
    if (!found.value) return;

    busy.value = true;
    problem.value = null;

    try {
        const { data } = await api.post(`/round/vehicles/${found.value.vehicle_id}/cloth`, {
            direction: 'pickup',
            cloth_count: count.value,
        });

        notice.value = data.message;
        found.value = null;
        registration.value = '';

        await refreshAfter(queryClient, 'cloth');
    } catch (e) {
        problem.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}

// ------------------------------------------------------------------ deliver

const { data: outstanding, isPending } = useQuery({
    queryKey: ['cloth', 'outstanding'],
    queryFn: async () => (await api.get('/cloth/outstanding')).data,
});

/** What the cleaner has ticked, and how many they are returning for each. */
const returning = ref<Record<string, number>>({});

function toggle(vehicleId: string, suggested: number) {
    if (returning.value[vehicleId] === undefined) returning.value[vehicleId] = suggested;
    else delete returning.value[vehicleId];
}

const tickedCount = computed(() => Object.keys(returning.value).length);

async function deliver() {
    busy.value = true;
    problem.value = null;
    notice.value = null;

    let done = 0;

    try {
        /*
         * One request per car rather than one for the batch. Each is its own
         * ledger entry and its own message to a customer, and a batch endpoint
         * that half succeeded would leave nobody able to say which half.
         */
        for (const [vehicleId, clothCount] of Object.entries(returning.value)) {
            await api.post(`/round/vehicles/${vehicleId}/cloth`, {
                direction: 'delivery',
                cloth_count: clothCount,
            });

            done++;
        }

        notice.value = `${done} delivery/deliveries recorded.`;
        returning.value = {};

        await refreshAfter(queryClient, 'cloth');
    } catch (e) {
        problem.value = `${describeError(e).message} ${done} were recorded before this.`;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-semibold tracking-tight text-ink">Cloths</h1>

        <div class="mb-4 flex rounded border border-line-strong p-0.5 text-sm sm:w-80">
            <button
                type="button"
                class="flex-1 rounded px-3 py-1.5 transition"
                :class="tab === 'collect' ? 'bg-accent font-medium text-on-accent' : 'text-body hover:bg-sunk'"
                @click="tab = 'collect'"
            >
                Collect
            </button>
            <button
                type="button"
                class="flex-1 rounded px-3 py-1.5 transition"
                :class="tab === 'deliver' ? 'bg-accent font-medium text-on-accent' : 'text-body hover:bg-sunk'"
                @click="tab = 'deliver'"
            >
                Deliver
                <span v-if="outstanding?.meta?.cars" class="ms-1 text-xs">({{ outstanding.meta.cars }})</span>
            </button>
        </div>

        <p v-if="notice" class="mb-3 rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">{{ notice }}</p>
        <p v-if="problem" class="mb-3 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">{{ problem }}</p>

        <!-- Collect -->
        <section v-if="tab === 'collect'" class="max-w-lg">
            <form class="rounded-lg border border-line bg-surface p-4" @submit.prevent="lookup">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car number</span>
                    <div class="flex gap-2">
                        <input
                            v-model.trim="registration"
                            type="text"
                            required
                            placeholder="UP16AB1234"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                            type="submit"
                            :disabled="busy"
                            class="shrink-0 rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                        >
                            Find
                        </button>
                    </div>
                </label>
            </form>

            <div v-if="found" class="mt-4 rounded-lg border border-line-strong bg-surface p-4">
                <p class="text-lg font-semibold uppercase tracking-wide text-ink">{{ found.registration }}</p>
                <p class="text-sm text-body">{{ found.customer }}</p>
                <p class="text-sm text-muted">
                    {{ [found.house_no, found.society].filter(Boolean).join(', ') || 'No address on file' }}
                </p>

                <p class="mt-3 text-sm text-body">
                    Balance now <strong class="tabular-nums text-ink">{{ found.balance }}</strong> cloth(s)
                </p>

                <!-- Shown so a second tap reads as a correction rather than
                     leaving somebody wondering whether the first one saved. -->
                <p v-if="Number(found.collected_today) > 0" class="mt-1 rounded bg-warn-soft px-2 py-1 text-xs text-warn">
                    {{ found.collected_today }} already collected from this car today. Saving again replaces that.
                </p>

                <form class="mt-4 flex items-end gap-2" @submit.prevent="collect">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Cloths taken</span>
                        <input
                            v-model.number="count"
                            type="number"
                            min="1"
                            max="500"
                            required
                            class="w-28 rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </label>

                    <button
                        type="submit"
                        :disabled="busy"
                        class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    >
                        {{ busy ? 'Saving…' : 'Record pickup' }}
                    </button>
                </form>
            </div>
        </section>

        <!-- Deliver -->
        <section v-else>
            <p v-if="isPending" class="text-muted">Loading…</p>

            <p
                v-else-if="!(outstanding?.data ?? []).length"
                class="rounded-lg border border-line bg-surface px-4 py-8 text-center text-muted"
            >
                Nothing is out at the laundry.
            </p>

            <template v-else>
                <p class="mb-3 text-sm text-muted">
                    <strong class="text-ink">{{ outstanding.meta.cloths }}</strong> cloth(s) from
                    <strong class="text-ink">{{ outstanding.meta.cars }}</strong> car(s) are out.
                </p>

                <!-- Grouped by society, because the round is walked that way. -->
                <div v-for="group in outstanding.data" :key="group.society" class="mb-4">
                    <h2 class="mb-2 flex items-baseline gap-2 text-sm font-semibold text-ink">
                        {{ group.society }}
                        <span class="text-xs font-normal text-muted">{{ group.total_cloths }} cloth(s)</span>
                    </h2>

                    <ul class="overflow-hidden rounded-lg border border-line bg-surface">
                        <li
                            v-for="car in group.cars"
                            :key="car.movement_id"
                            class="flex flex-wrap items-center gap-3 border-b border-line px-3 py-2 last:border-0"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-line-strong"
                                :checked="returning[car.vehicle_id] !== undefined"
                                :aria-label="'Return cloths to ' + car.registration"
                                @change="toggle(car.vehicle_id, car.cloth_count)"
                            />

                            <div class="min-w-0">
                                <p class="font-medium uppercase tracking-wide text-ink">{{ car.registration }}</p>
                                <p class="text-xs text-muted">
                                    {{ [car.house_no, car.customer].filter(Boolean).join(' · ') }}
                                </p>
                            </div>

                            <div class="ms-auto flex items-center gap-3">
                                <span
                                    class="text-xs tabular-nums"
                                    :class="car.days_out > 2 ? 'text-warn' : 'text-faint'"
                                >
                                    {{ car.days_out === 0 ? 'today' : car.days_out + 'd out' }}
                                </span>

                                <input
                                    v-if="returning[car.vehicle_id] !== undefined"
                                    v-model.number="returning[car.vehicle_id]"
                                    type="number"
                                    min="1"
                                    :max="car.cloth_count"
                                    class="w-20 rounded border border-line-strong bg-surface px-2 py-1 text-sm tabular-nums text-ink focus:border-accent focus:outline-none"
                                />
                                <span v-else class="w-20 text-end text-sm tabular-nums text-body">
                                    {{ car.cloth_count }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>

                <button
                    type="button"
                    class="sticky bottom-4 rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent shadow-lg transition hover:brightness-110 disabled:opacity-50"
                    :disabled="busy || tickedCount === 0"
                    @click="deliver"
                >
                    {{ busy ? 'Saving…' : `Record ${tickedCount} delivery/deliveries` }}
                </button>
            </template>
        </section>
    </div>
</template>
