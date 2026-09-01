<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { api, describeError } from '@/shared/api/client';
import { payForClothTopUpByCar, type PaymentReceipt } from '@/shared/api/checkout';

/**
 * Buying more cloths by quoting a car number.
 *
 * One of the four things the requirements document puts on the home page. No
 * account needed - most customers never make one - and a car we do not know is
 * offered the signup page rather than left at a dead end, because somebody
 * typing a car number into a top-up box is a customer either way.
 */
interface Bundle { id: string; name: string; cloths: number; price: number }

interface Found {
    subscription_id: string;
    registration: string;
    name: string;
    balance: number;
    bundles: Bundle[];
}

const registration = ref('');
const found = ref<Found | null>(null);
const chosen = ref<string>('');
const busy = ref(false);
const problem = ref<string | null>(null);
const outcome = ref<string | null>(null);
const offerSignup = ref(false);

/**
 * Paid, and what for. Stays until the page is left.
 *
 * Deliberately not a line of green text that clears itself: this is the only
 * acknowledgement a customer with no account ever gets, and the new balance is
 * the thing they came to find out.
 */
const paid = ref<{
    car: string;
    bought: number | null;
    balance: number;
    receipt: PaymentReceipt | undefined;
} | null>(null);

async function lookup() {
    busy.value = true;
    problem.value = null;
    outcome.value = null;
    offerSignup.value = false;
    found.value = null;

    try {
        const { data } = await api.post('/public/cloth/lookup', { registration: registration.value });

        found.value = data.data;
        chosen.value = data.data.bundles[0]?.id ?? '';
    } catch (e) {
        const described = describeError(e);
        problem.value = described.message;

        // The server says when signing up is the right next step.
        offerSignup.value = Boolean((e as { response?: { data?: { subscribe_instead?: boolean } } })
            ?.response?.data?.subscribe_instead);
    } finally {
        busy.value = false;
    }
}

async function pay() {
    if (!found.value || !chosen.value) return;

    busy.value = true;
    problem.value = null;

    const result = await payForClothTopUpByCar(
        found.value.subscription_id,
        found.value.registration,
        chosen.value,
    );

    busy.value = false;

    if (result.ok) {
        /*
         * The receipt, not just "Payment received".
         *
         * Same as the renewal page: this is the last thing the customer sees
         * and it has to be worth reading. The car and the new balance matter
         * more than the sentence, because "how many have I got now" is the
         * question that brought them here.
         */
        const bundle = found.value.bundles.find((b) => b.id === chosen.value);

        paid.value = {
            car: found.value.registration,
            bought: bundle?.cloths ?? null,
            balance: found.value.balance + (bundle?.cloths ?? 0),
            receipt: result.payment,
        };

        outcome.value = null;
        found.value = null;
        registration.value = '';
        return;
    }

    if (result.cancelled) {
        outcome.value = result.message;
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
    <div class="mx-auto max-w-2xl px-4 py-10">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Top up your cloths</h1>
        <p class="mt-1 text-body">
            Quote your car number and we will show you what is left and what you can buy.
        </p>

        <form class="mt-6 rounded-lg border border-line bg-surface p-4" @submit.prevent="lookup">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-body">Car number</span>
                <div class="flex flex-wrap gap-2">
                    <input
                        v-model.trim="registration"
                        type="text"
                        required
                        placeholder="UP16AB1234"
                        class="min-w-0 flex-1 rounded border border-line-strong bg-surface px-3 py-2.5 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                    <button
                        type="submit"
                        :disabled="busy"
                        class="rounded bg-accent px-6 py-2.5 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    >
                        {{ busy ? 'Looking…' : 'Find my car' }}
                    </button>
                </div>
            </label>
        </form>

        <!-- Paid. The balance first, because that is what they came for. -->
        <div v-if="paid" class="mt-4 rounded-lg border border-ok bg-ok-soft p-5">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-6 w-6 shrink-0 text-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <div>
                    <p class="font-semibold text-ink">Payment received</p>
                    <p class="mt-1 text-sm text-body">
                        <template v-if="paid.bought">{{ paid.bought }} cloths added to </template>
                        <span class="font-medium uppercase text-ink">{{ paid.car }}</span>.
                        The balance is now <strong class="tabular-nums text-ink">{{ paid.balance }}</strong>.
                    </p>
                    <p v-if="paid.receipt" class="mt-1 text-sm text-muted">
                        {{ money(paid.receipt.amount) }} paid<template v-if="paid.receipt.invoice_number">,
                        invoice {{ paid.receipt.invoice_number }}</template>.
                    </p>
                    <p class="mt-2 text-xs text-faint">
                        The cleaner brings them on the next round.
                    </p>
                </div>
            </div>
        </div>

        <p v-if="outcome" class="mt-4 rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">
            {{ outcome }}
        </p>

        <div v-if="problem" class="mt-4 rounded border border-line bg-surface p-4">
            <p class="text-body">{{ problem }}</p>

            <!-- Not a dead end: somebody typing a car number here is a
                 customer, known to us or not. -->
            <RouterLink
                v-if="offerSignup"
                :to="{ name: 'subscribe' }"
                class="mt-3 inline-block rounded bg-accent px-5 py-2.5 text-sm font-semibold text-on-accent transition hover:brightness-110"
            >
                Start a plan instead
            </RouterLink>
        </div>

        <div v-if="found" class="mt-6 rounded-lg border border-line-strong bg-surface p-5">
            <p class="text-sm text-muted">Hello {{ found.name }} — car {{ found.registration }}</p>

            <p class="mt-2 text-body">
                You have <strong class="text-2xl font-bold tabular-nums text-ink">{{ found.balance }}</strong>
                cloth(s) left.
            </p>

            <fieldset class="mt-5">
                <legend class="mb-2 text-sm font-medium text-body">Choose a top-up</legend>

                <div class="flex flex-col gap-2">
                    <label
                        v-for="bundle in found.bundles"
                        :key="bundle.id"
                        class="flex cursor-pointer items-center gap-3 rounded border px-3 py-2.5 transition"
                        :class="chosen === bundle.id ? 'border-accent bg-accent-soft' : 'border-line-strong hover:bg-sunk'"
                    >
                        <input v-model="chosen" type="radio" :value="bundle.id" class="accent-[var(--accent)]" />
                        <span class="text-ink">{{ bundle.name }}</span>
                        <span class="text-sm text-muted">{{ bundle.cloths }} cloths</span>
                        <span class="ms-auto font-semibold tabular-nums text-ink">{{ money(bundle.price) }}</span>
                    </label>
                </div>

                <p v-if="!found.bundles.length" class="text-sm text-muted">
                    No cloth plans are on sale at the moment. Please call the office.
                </p>
            </fieldset>

            <button
                type="button"
                class="mt-5 w-full rounded bg-accent px-6 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60"
                :disabled="busy || !chosen"
                @click="pay"
            >
                {{ busy ? 'Please wait…' : 'Pay and top up' }}
            </button>

            <p class="mt-3 text-xs text-faint">
                The price is ours, not your browser's — it is confirmed again before anything is charged.
            </p>
        </div>
    </div>
</template>
