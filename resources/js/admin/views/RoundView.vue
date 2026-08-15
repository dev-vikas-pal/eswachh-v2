<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';

/**
 * A cleaner's day.
 *
 * Built for a phone held in one hand beside a car, not for a desk: big targets,
 * one card per stop, and the thing you came to do is the first button. An admin
 * table would be unusable in a basement car park.
 */
const auth = useAuthStore();
const queryClient = useQueryClient();

const busyVehicle = ref<string | null>(null);
const clothFor = ref<string | null>(null);
const clothCount = ref(1);
const clothDirection = ref<'pickup' | 'delivery'>('pickup');
const error = ref<string | null>(null);

const { data, isPending } = useQuery({
    queryKey: ['round'],
    queryFn: async () => (await api.get('/round')).data.data,
    // A round is worked over hours; nothing here changes behind your back.
    staleTime: 60_000,
});

const summary = computed(() => data.value?.summary ?? null);
const stops = computed(() => data.value?.stops ?? []);

/** The ones still to do, so the list shortens as the morning goes on. */
const remaining = computed(() => stops.value.filter((s: { done: boolean }) => !s.done));
const finished = computed(() => stops.value.filter((s: { done: boolean }) => s.done));

const outcomes = [
    { value: 'cleaned', label: 'Cleaned', tone: 'ok' },
    { value: 'car_absent', label: 'Car not there', tone: 'muted' },
    { value: 'access_denied', label: 'Could not reach it', tone: 'warn' },
    { value: 'customer_declined', label: 'Customer declined', tone: 'muted' },
];

async function record(vehicleId: string, outcome: string) {
    busyVehicle.value = vehicleId;
    error.value = null;

    try {
        await api.post(`/round/vehicles/${vehicleId}`, { outcome });
        await queryClient.invalidateQueries({ queryKey: ['round'] });
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        busyVehicle.value = null;
    }
}

async function saveCloth() {
    if (!clothFor.value) return;

    busyVehicle.value = clothFor.value;
    error.value = null;

    try {
        await api.post(`/round/vehicles/${clothFor.value}/cloth`, {
            direction: clothDirection.value,
            cloth_count: clothCount.value,
        });
        clothFor.value = null;
        clothCount.value = 1;
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        busyVehicle.value = null;
    }
}

async function markPresent() {
    await api.post('/attendance', { status: 'present' });
    await queryClient.invalidateQueries({ queryKey: ['round'] });
}
</script>

<template>
    <div class="mx-auto max-w-lg">
        <div class="mb-4">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Today's round</h1>
            <p v-if="summary" class="text-sm text-muted">
                {{ summary.cleaned }} of {{ summary.due }} done
                <span v-if="summary.unaccounted" class="text-warn">
                    · {{ summary.unaccounted }} not yet recorded
                </span>
            </p>
        </div>

        <!-- Marking yourself present is the first thing, so it is the first button. -->
        <button
            v-if="summary && !summary.attendance"
            type="button"
            class="mb-4 w-full rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110"
            @click="markPresent"
        >
            I am working today
        </button>

        <p v-if="error" class="mb-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ error }}</p>

        <p v-if="isPending" class="text-muted">Loading your round…</p>

        <p v-else-if="!stops.length" class="rounded-lg border border-line bg-surface px-4 py-8 text-center text-muted">
            No cars assigned to you yet. Ask the office.
        </p>

        <!-- Still to do -->
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="stop in remaining"
                :key="stop.vehicle.id"
                class="rounded-lg border border-line-strong bg-surface p-4"
            >
                <div class="flex items-baseline gap-2">
                    <span class="text-lg font-bold tracking-wide text-ink">{{ stop.vehicle.registration }}</span>
                    <span v-if="stop.customer?.preferred_time" class="ms-auto text-sm tabular-nums text-muted">
                        {{ stop.customer.preferred_time }}
                    </span>
                </div>

                <p class="mt-0.5 text-sm text-body">
                    {{ stop.customer?.name }}
                    <span v-if="stop.customer?.house_no" class="text-muted">· {{ stop.customer.house_no }}</span>
                </p>

                <!-- Calling the customer is one tap, not a number to copy out. -->
                <a
                    v-if="stop.customer?.phone"
                    :href="'tel:' + stop.customer.phone"
                    class="mt-1 inline-block text-sm text-accent-ink hover:underline"
                >
                    Call {{ stop.customer.phone }}
                </a>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-for="o in outcomes"
                        :key="o.value"
                        type="button"
                        :disabled="busyVehicle === stop.vehicle.id"
                        class="rounded px-3 py-2 text-sm font-medium transition disabled:opacity-50"
                        :class="o.value === 'cleaned'
                            ? 'bg-accent text-on-accent hover:brightness-110'
                            : 'border border-line-strong text-body hover:bg-sunk'"
                        @click="record(stop.vehicle.id, o.value)"
                    >
                        {{ o.label }}
                    </button>
                </div>

                <button
                    v-if="auth.can('record.cloth')"
                    type="button"
                    class="mt-2 text-xs text-muted hover:text-ink"
                    @click="clothFor = stop.vehicle.id; clothDirection = 'pickup'"
                >
                    Cloths…
                </button>

                <!-- Cloth pickup and delivery, in place rather than on another screen. -->
                <div v-if="clothFor === stop.vehicle.id" class="mt-2 rounded border border-line bg-sunk p-3">
                    <div class="flex gap-2">
                        <button
                            v-for="d in (['pickup', 'delivery'] as const)"
                            :key="d"
                            type="button"
                            class="flex-1 rounded px-3 py-2 text-sm font-medium transition"
                            :class="clothDirection === d ? 'bg-accent text-on-accent' : 'border border-line-strong text-body'"
                            @click="clothDirection = d"
                        >
                            {{ d === 'pickup' ? 'Collected' : 'Returned' }}
                        </button>
                    </div>

                    <div class="mt-2 flex items-center gap-2">
                        <input
                            v-model.number="clothCount"
                            type="number" min="1" max="500" inputmode="numeric"
                            class="w-20 rounded border border-line-strong bg-surface px-3 py-2 text-center text-lg tabular-nums text-ink focus:border-accent focus:outline-none"
                        />
                        <button
                            type="button"
                            class="flex-1 rounded bg-accent px-3 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110"
                            @click="saveCloth"
                        >
                            Save
                        </button>
                        <button type="button" class="rounded border border-line-strong px-3 py-2 text-sm text-body" @click="clothFor = null">
                            Cancel
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <!-- Done, collapsed out of the way but still checkable -->
        <details v-if="finished.length" class="mt-5">
            <summary class="cursor-pointer text-sm font-medium text-muted">
                {{ finished.length }} already recorded
            </summary>
            <ul class="mt-2 flex flex-col gap-1">
                <li
                    v-for="stop in finished"
                    :key="stop.vehicle.id"
                    class="flex items-baseline gap-2 rounded border border-line bg-surface px-3 py-2 text-sm"
                >
                    <span class="font-medium text-ink">{{ stop.vehicle.registration }}</span>
                    <span
                        class="ms-auto rounded px-2 py-0.5 text-xs"
                        :class="stop.log?.was_cleaned ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'"
                    >
                        {{ stop.log?.outcome.label }}
                    </span>
                    <!-- Correcting a mistake matters more than tidiness here. -->
                    <button type="button" class="text-xs text-accent-ink hover:underline" @click="record(stop.vehicle.id, 'cleaned')">
                        change
                    </button>
                </li>
            </ul>
        </details>
    </div>
</template>
