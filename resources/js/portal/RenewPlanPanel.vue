<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { payForRenewal, type PaymentReceipt } from '@/shared/api/checkout';
import RenewalNotice, { type RenewalTiming } from '@/shared/RenewalNotice.vue';
import type { PortalPlan } from '@/portal/portal.api';

/**
 * Renewing, with the chance to change what is being bought.
 *
 * v1 let a customer pick a package and a duration at renewal; v2 silently
 * re-bought whatever they had last time. That matters most for duration - the
 * business discounts three and six month plans, and a customer who cannot see
 * that at the moment they are paying never takes it.
 *
 * The price still comes from the server on every change. Nothing here decides
 * an amount; the quote is display only and the server prices it again when the
 * payment is opened.
 */
const props = defineProps<{ plan: PortalPlan; clothServiceOn: boolean }>();

const emit = defineEmits<{
    (e: 'close'): void;
    /**
     * Paid, with the receipt.
     *
     * Carried out of here rather than left behind: the panel closes on success,
     * and a customer who has just been charged should not be returned to the
     * same list with no acknowledgement that anything happened.
     */
    (e: 'done', receipt: PaymentReceipt | undefined): void;
}>();

const busy = ref(false);
const problem = ref<string | null>(null);

/** What they are renewing onto. Starts as what they already have. */
const choice = ref({
    package_id: '',
    service_type_id: '',
    duration_id: '',
    cloth_bundle_id: '',
});

const { data: catalogue } = useQuery({
    queryKey: ['public-catalogue'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});

/** The plan as it stands, so the form opens on their current choices. */
const { data: current } = useQuery({
    queryKey: computed(() => ['subscription', props.plan.id]),
    queryFn: async () => (await api.get(`/subscriptions/${props.plan.id}`)).data.data,
});

watch(current, (plan) => {
    if (!plan) return;

    choice.value = {
        package_id: plan.package_id ?? '',
        service_type_id: plan.service_type_id ?? '',
        duration_id: plan.duration_id ?? '',
        cloth_bundle_id: plan.cloth_bundle_id ?? '',
    };
}, { immediate: true });

/**
 * A quote, refreshed as the choices change. Display only.
 *
 * The society goes with it. Without it this priced the plan as though the
 * customer lived nowhere in particular, dropped the surcharge their address
 * carries, and showed a total the payment window then disagreed with - which
 * is the one moment in the whole flow where a surprise is least affordable.
 */
const { data: quote, isFetching: pricing } = useQuery({
    queryKey: computed(() => ['quote', 'renew', props.plan.id, ...Object.values(choice.value)]),
    enabled: computed(() => !!choice.value.duration_id && !!current.value),
    queryFn: async () => (await api.post('/public/quote', {
        vehicle_model_id: current.value?.vehicle?.vehicle_model_id ?? null,
        society_id: current.value?.customer?.society_id ?? null,
        package_id: choice.value.package_id || null,
        service_type_id: choice.value.service_type_id || null,
        duration_id: choice.value.duration_id,
        cloth_bundle_id: props.clothServiceOn ? (choice.value.cloth_bundle_id || null) : null,
    })).data.data,
});

/** Where the plan stands against its end date, from the plan itself. */
const timing = computed<RenewalTiming | null>(() => current.value?.timing ?? null);

async function pay() {
    busy.value = true;
    problem.value = null;

    /*
     * One call. The choices go with the renewal rather than through the edit
     * endpoint, which needs a permission a customer does not have and should
     * not be given just to change their own plan. The server applies them,
     * prices the result from the masters, and opens the payment for that
     * figure - so nothing here sends an amount.
     */
    const result = await payForRenewal(props.plan.id, {}, {
        package_id: choice.value.package_id,
        service_type_id: choice.value.service_type_id,
        duration_id: choice.value.duration_id,
        cloth_bundle_id: props.clothServiceOn ? (choice.value.cloth_bundle_id || null) : null,
    });

    busy.value = false;

    if (result.ok) {
        emit('done', result.payment);
        return;
    }

    problem.value = result.message;
}

function money(rupees: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10" @click.self="emit('close')">
        <div class="w-full max-w-lg rounded-lg border border-line-strong bg-surface p-5 shadow-xl">
            <h2 class="text-lg font-semibold text-ink">
                Renew {{ plan.vehicle?.registration ?? 'your plan' }}
            </h2>
            <p class="mt-1 text-sm text-muted">
                Keep what you have, or change it before you pay.
            </p>

            <!--
                Whether this is early, due or overdue - said before the form
                rather than after it, because it changes whether somebody wants
                to be on this screen at all.
            -->
            <RenewalNotice :timing="timing" class="mt-4" />

            <div class="mt-4 flex flex-col gap-3">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Package</span>
                    <select v-model="choice.package_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="p in catalogue?.packages ?? []" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Interior cleaning</span>
                    <select v-model="choice.service_type_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="t in catalogue?.service_types ?? []" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">How long</span>
                    <select v-model="choice.duration_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="d in catalogue?.durations ?? []" :key="d.id" :value="d.id">
                            {{ d.name }}<span v-if="d.discount > 0"> — save {{ money(d.discount) }}</span>
                        </option>
                    </select>
                    <span class="mt-1 block text-xs text-faint">
                        Longer plans cost less per month.
                    </span>
                </label>

                <!-- Only when the business is running the service. -->
                <label v-if="clothServiceOn" class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Cloth ironing</span>
                    <select v-model="choice.cloth_bundle_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
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
                    <span class="text-xl font-bold tabular-nums text-ink">
                        {{ quote?.formatted ?? '—' }}
                    </span>
                </div>

                <p v-if="pricing" class="mt-1 text-xs text-faint">updating…</p>

                <p v-for="w in quote?.warnings ?? []" :key="w" class="mt-2 rounded bg-warn-soft px-2 py-1 text-xs text-warn">
                    {{ w }}
                </p>

                <p class="mt-2 text-xs text-faint">
                    Worked out by us, not by your browser, and confirmed again before anything is charged.
                </p>
            </div>

            <p v-if="problem" class="mt-3 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                {{ problem }}
            </p>

            <div class="mt-4 flex gap-2">
                <button
                    type="button"
                    class="rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    :disabled="busy || !quote"
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
