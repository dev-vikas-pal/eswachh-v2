<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { payForNewPlan, type PaymentReceipt } from '@/shared/api/checkout';

/**
 * A second car, for somebody who already has an account.
 *
 * Short on purpose. They are signed in, so there is no name, no phone, no
 * address and no code to prove a number they have already proved - the office
 * knows where they live and which franchise services them. What is left is the
 * car and what they want done to it.
 */
const props = defineProps<{
    clothServiceOn: boolean;
    profile?: { name?: string | null; email?: string | null; phone?: string | null } | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    /** Paid, with the receipt and the car it was for. */
    (e: 'done', receipt: PaymentReceipt | undefined, registration: string): void;
}>();

const form = ref({
    registration: '',
    vehicle_model_id: '',
    package_id: '',
    service_type_id: '',
    duration_id: '',
    cloth_bundle_id: '',
});

const busy = ref(false);
const problem = ref<string | null>(null);

const { data: catalogue } = useQuery({
    queryKey: ['public-catalogue'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});

// Open on the first of each list, so the price appears without four choices.
watch(catalogue, (c) => {
    if (!c) return;

    form.value.package_id ||= c.packages?.[0]?.id ?? '';
    form.value.service_type_id ||= c.service_types?.[0]?.id ?? '';
    form.value.duration_id ||= c.durations?.[0]?.id ?? '';
}, { immediate: true });

/** The server's price, refreshed as the choices change. Display only. */
const { data: quote, isFetching: pricing } = useQuery({
    queryKey: computed(() => ['quote', 'new-car', ...Object.values(form.value)]),
    enabled: computed(() => !!form.value.duration_id),
    queryFn: async () => (await api.post('/public/quote', {
        vehicle_model_id: form.value.vehicle_model_id || null,
        package_id: form.value.package_id || null,
        service_type_id: form.value.service_type_id || null,
        duration_id: form.value.duration_id,
        cloth_bundle_id: props.clothServiceOn ? (form.value.cloth_bundle_id || null) : null,
    })).data.data,
});

const ready = computed(() =>
    form.value.registration.trim().length >= 4
    && !!form.value.vehicle_model_id
    && !!form.value.duration_id);

async function pay() {
    busy.value = true;
    problem.value = null;

    const result = await payForNewPlan({
        registration: form.value.registration,
        vehicle_model_id: form.value.vehicle_model_id,
        package_id: form.value.package_id,
        service_type_id: form.value.service_type_id,
        duration_id: form.value.duration_id,
        cloth_bundle_id: props.clothServiceOn ? (form.value.cloth_bundle_id || null) : null,
    }, {
        name: props.profile?.name ?? '',
        email: props.profile?.email ?? '',
        phone: props.profile?.phone ?? '',
    });

    busy.value = false;

    if (result.ok) {
        emit('done', result.payment, form.value.registration);
        return;
    }

    if (result.cancelled) {
        problem.value = result.message;
        return;
    }

    problem.value = result.message;
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10" @click.self="emit('close')">
        <div class="w-full max-w-lg rounded-lg border border-line-strong bg-surface p-5 shadow-xl">
            <h2 class="text-lg font-semibold text-ink">Add another car</h2>
            <p class="mt-1 text-sm text-muted">
                We already have your address and phone number, so there is nothing to type twice.
            </p>

            <div class="mt-4 flex flex-col gap-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car number</span>
                        <input
                            v-model.trim="form.registration"
                            type="text"
                            required
                            placeholder="UP16AB1234"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car model</span>
                        <select v-model="form.vehicle_model_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                            <option value="">Choose…</option>
                            <option v-for="m in catalogue?.car_models ?? []" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Package</span>
                    <select v-model="form.package_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="p in catalogue?.packages ?? []" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Interior cleaning</span>
                    <select v-model="form.service_type_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="t in catalogue?.service_types ?? []" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">How long</span>
                    <select v-model="form.duration_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="d in catalogue?.durations ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </label>

                <label v-if="clothServiceOn" class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Cloth ironing</span>
                    <select v-model="form.cloth_bundle_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="">Not this time</option>
                        <option v-for="b in catalogue?.cloth_bundles ?? []" :key="b.id" :value="b.id">
                            {{ b.name }} — {{ b.count }} cloths
                        </option>
                    </select>
                </label>
            </div>

            <div class="mt-4 rounded border border-line bg-sunk p-3">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm text-body">Total</span>
                    <span class="text-xl font-bold tabular-nums text-ink">{{ quote?.formatted ?? '—' }}</span>
                </div>

                <p v-if="pricing" class="mt-1 text-xs text-faint">updating…</p>

                <p v-for="w in quote?.warnings ?? []" :key="w" class="mt-2 rounded bg-warn-soft px-2 py-1 text-xs text-warn">
                    {{ w }}
                </p>
            </div>

            <p v-if="problem" class="mt-3 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                {{ problem }}
            </p>

            <div class="mt-4 flex gap-2">
                <button
                    type="button"
                    class="rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    :disabled="busy || !ready || !quote"
                    @click="pay"
                >
                    {{ busy ? 'Please wait…' : `Pay ${quote?.formatted ?? ''}` }}
                </button>

                <button
                    type="button"
                    class="rounded border border-line-strong px-4 py-2.5 text-sm text-body transition hover:bg-sunk"
                    @click="emit('close')"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>
