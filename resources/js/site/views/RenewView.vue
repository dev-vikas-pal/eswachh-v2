<script setup lang="ts">
import { ref } from 'vue';
import { api, describeError } from '@/shared/api/client';
import { payForFoundPlan } from '@/shared/api/checkout';

/**
 * Renewing without an account.
 *
 * Most customers never make one, and asking somebody to remember a password
 * before they can pay you is a good way not to be paid. A car number is
 * something they can read off the boot.
 */
interface Found {
    subscription_id: string;
    registration: string;
    name: string;
    renews_on: string | null;
    status: string;
    amount: number;
    formatted: string;
}

const registration = ref('');
const looking = ref(false);
const paying = ref(false);
const found = ref<Found | null>(null);
const notFound = ref<string | null>(null);
const result = ref<{ ok: boolean; message: string } | null>(null);

async function lookup() {
    looking.value = true;
    notFound.value = null;
    found.value = null;
    result.value = null;

    try {
        const { data } = await api.post('/public/renew/lookup', {
            registration: registration.value,
        });
        found.value = data.data;
    } catch (e) {
        // The same answer whether the car is unknown or has no live plan, so
        // this never confirms which cars are customers.
        notFound.value = describeError(e).message;
    } finally {
        looking.value = false;
    }
}

async function pay() {
    if (!found.value) return;

    paying.value = true;
    result.value = null;

    result.value = await payForFoundPlan(found.value.subscription_id, registration.value);
    paying.value = false;

    if (result.value.ok) found.value = null;
}
</script>

<template>
    <div class="mx-auto max-w-xl px-4 py-12">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Renew your plan</h1>
        <p class="mt-1 text-body">
            No account needed. Type your car number and we will find your plan.
        </p>

        <form class="mt-6 flex flex-wrap gap-2" @submit.prevent="lookup">
            <input
                v-model.trim="registration"
                type="text"
                required
                placeholder="UP16AB1234"
                class="min-w-0 flex-1 rounded border border-line-strong bg-surface px-4 py-3 text-lg uppercase tracking-wide text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            />
            <button
                type="submit"
                :disabled="looking"
                class="rounded bg-accent px-6 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60"
            >
                {{ looking ? 'Looking…' : 'Find my plan' }}
            </button>
        </form>

        <p v-if="notFound" class="mt-4 rounded border border-line bg-surface px-4 py-3 text-sm text-body">
            {{ notFound }}
        </p>

        <div v-if="found" class="mt-6 rounded-lg border border-line-strong bg-surface p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Your plan</p>

            <p class="mt-1 text-lg font-semibold text-ink">
                {{ found.registration }}
                <span class="text-base font-normal text-muted">· {{ found.name }}</span>
            </p>

            <dl class="mt-3 flex flex-col gap-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted">Renews on</dt>
                    <dd class="tabular-nums text-ink">{{ found.renews_on ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted">Status</dt>
                    <dd class="text-ink">{{ found.status }}</dd>
                </div>
                <div class="flex items-baseline justify-between border-t border-line pt-2">
                    <dt class="font-semibold text-ink">To pay</dt>
                    <dd class="text-xl font-bold tabular-nums text-ink">{{ found.formatted }}</dd>
                </div>
            </dl>

            <button
                type="button"
                :disabled="paying"
                class="mt-4 w-full rounded bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60"
                @click="pay"
            >
                {{ paying ? 'Opening payment…' : 'Pay ' + found.formatted }}
            </button>

            <p class="mt-2 text-xs text-faint">
                This price is worked out by us at today's rates, not by your browser.
            </p>
        </div>

        <p
            v-if="result"
            class="mt-4 rounded px-4 py-3 text-sm"
            :class="result.ok ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'"
        >
            {{ result.message }}
        </p>
    </div>
</template>
