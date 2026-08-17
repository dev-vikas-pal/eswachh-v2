<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { payForClothTopUp } from '@/shared/api/checkout';
import type { PortalPlan } from '@/portal/portal.api';

/**
 * Buying more cloths for a plan already on screen.
 *
 * Reached from the plan rather than from the navigation: somebody thinking
 * about cloths is looking at their car and its balance, and a top-up page that
 * starts by asking for a car number is asking a question they have already
 * answered by getting here.
 */
const props = defineProps<{ plan: PortalPlan }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'done'): void }>();

const chosen = ref('');
const busy = ref(false);
const problem = ref<string | null>(null);

const { data: catalogue } = useQuery({
    queryKey: ['public-catalogue'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});

const bundles = computed<Array<{ id: string; name: string; count: number; price: number }>>(
    () => catalogue.value?.cloth_bundles ?? [],
);

const selected = computed(() => bundles.value.find((b) => b.id === chosen.value) ?? null);

async function pay() {
    if (!chosen.value) return;

    busy.value = true;
    problem.value = null;

    const result = await payForClothTopUp(props.plan.id, chosen.value);

    busy.value = false;

    if (result.ok) {
        emit('done');
        return;
    }

    problem.value = result.message;
}

function money(rupees: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 })
        .format(rupees);
}
</script>

<template>
    <div class="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-black/40 p-4" @click.self="emit('close')">
        <div class="w-full max-w-md rounded-lg border border-line-strong bg-surface p-5 shadow-xl">
            <h2 class="text-lg font-semibold text-ink">Top up cloths</h2>
            <p class="mt-1 text-sm text-muted">
                {{ plan.vehicle?.registration }} — <strong class="tabular-nums text-ink">{{ plan.cloth.balance }}</strong> left
            </p>

            <fieldset class="mt-4">
                <legend class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">Choose a top-up</legend>

                <div class="flex flex-col gap-2">
                    <label
                        v-for="bundle in bundles"
                        :key="bundle.id"
                        class="flex cursor-pointer items-center gap-3 rounded border px-3 py-2.5 transition"
                        :class="chosen === bundle.id ? 'border-accent bg-accent-soft' : 'border-line-strong hover:bg-sunk'"
                    >
                        <input v-model="chosen" type="radio" :value="bundle.id" class="accent-[var(--accent)]" />
                        <span class="text-ink">{{ bundle.name }}</span>
                        <span class="text-sm text-muted">{{ bundle.count }} cloths</span>
                        <span class="ms-auto font-semibold tabular-nums text-ink">{{ money(bundle.price) }}</span>
                    </label>
                </div>

                <p v-if="!bundles.length" class="text-sm text-muted">
                    No cloth plans are on sale at the moment. Please call the office.
                </p>
            </fieldset>

            <p v-if="selected" class="mt-3 rounded bg-sunk px-3 py-2 text-sm text-body">
                Balance after this top-up:
                <strong class="tabular-nums text-ink">{{ plan.cloth.balance + selected.count }}</strong>
            </p>

            <p v-if="problem" class="mt-3 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                {{ problem }}
            </p>

            <div class="mt-4 flex gap-2">
                <button
                    type="button"
                    class="rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    :disabled="busy || !chosen"
                    @click="pay"
                >
                    {{ busy ? 'Please wait…' : `Pay ${selected ? money(selected.price) : ''}` }}
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
