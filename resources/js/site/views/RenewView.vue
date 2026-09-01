<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { api, describeError } from '@/shared/api/client';
import { payForFoundPlan, type PaymentReceipt } from '@/shared/api/checkout';
import RenewalNotice, { type RenewalTiming } from '@/shared/RenewalNotice.vue';

/**
 * Renewing without an account.
 *
 * Most customers never make one, and asking somebody to remember a password
 * before they can pay you is a good way not to be paid. A car number is
 * something they can read off the boot.
 */
interface QuoteLine {
    source: string;
    label: string;
    amount: number;
    recurring: boolean;
}

interface Found {
    subscription_id: string;
    registration: string;
    name: string;
    renews_on: string | null;
    status: string;
    amount: number;
    formatted: string;
    lines: QuoteLine[];
    months: number;
    timing: RenewalTiming;
}

const registration = ref('');
const looking = ref(false);
const paying = ref(false);
const found = ref<Found | null>(null);
const notFound = ref<string | null>(null);

/**
 * What went wrong, when something did.
 *
 * Kept apart from the paid state below. A failure belongs beside the button
 * that failed, where they can try it again; success replaces the page.
 */
const problem = ref<string | null>(null);

/**
 * Paid.
 *
 * A page of its own, as the signup has - what somebody wants at this moment is
 * proof of what they bought and what happens next. A green line under a form
 * they have finished with says none of that, and leaves the form on screen
 * inviting them to pay a second time.
 */
const paid = ref<{ receipt?: PaymentReceipt; car: string; renewed: Found } | null>(null);

async function lookup() {
    looking.value = true;
    notFound.value = null;
    found.value = null;
    problem.value = null;

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

    const plan = found.value;

    paying.value = true;
    problem.value = null;

    const result = await payForFoundPlan(plan.subscription_id, registration.value);
    paying.value = false;

    if (result.ok) {
        paid.value = { receipt: result.payment, car: plan.registration, renewed: plan };
        found.value = null;
        return;
    }

    problem.value = result.message;
}

/** Back to the start, for somebody renewing a second car. */
function again() {
    paid.value = null;
    registration.value = '';
}

const money = (rupees: number) =>
    new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);

const day = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <!-- Paid. Nothing else on the page. -->
    <div v-if="paid" class="mx-auto max-w-2xl px-4 py-12">
        <div class="rounded-lg border border-ok bg-ok-soft p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Renewal received</h1>
            <p class="mt-1 text-body">
                <span class="font-medium uppercase text-ink">{{ paid.car }}</span> is renewed. Nothing else to do.
            </p>
        </div>

        <div class="mt-6 rounded-lg border border-line bg-surface">
            <h2 class="border-b border-line px-5 py-3 text-sm font-semibold uppercase tracking-wide text-muted">
                Your renewal
            </h2>

            <dl class="divide-y divide-line text-sm">
                <div v-if="paid.receipt?.invoice_number" class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Invoice</dt>
                    <dd class="font-medium tabular-nums text-ink">{{ paid.receipt.invoice_number }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Car</dt>
                    <dd class="font-medium uppercase text-ink">{{ paid.car }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Length</dt>
                    <dd class="text-ink">{{ paid.renewed.months }} month<span v-if="paid.renewed.months !== 1">s</span></dd>
                </div>
                <div v-if="paid.receipt?.method" class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Paid by</dt>
                    <dd class="text-ink">{{ paid.receipt.method }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Paid</dt>
                    <dd class="text-lg font-bold tabular-nums text-ink">
                        {{ money(paid.receipt?.amount ?? paid.renewed.amount) }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="mt-6 rounded-lg border border-line bg-surface p-5 text-sm text-body">
            <h2 class="mb-2 font-semibold text-ink">What happens next</h2>
            <ol class="ms-4 list-decimal space-y-1">
                <li>
                    <template v-if="paid.renewed.timing.early">
                        Your new term is added on from {{ day(paid.renewed.timing.renews_on) }} — the days you
                        had left are still yours.
                    </template>
                    <template v-else>
                        Cleaning continues from the next round, with the same cleaner.
                    </template>
                </li>
                <li>You get a message each evening saying whether your car was done.</li>
                <li>Your receipt has been sent to the number on the account.</li>
            </ol>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    href="/login"
                    class="rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent transition hover:brightness-110"
                >
                    See my plan
                </a>
                <button
                    type="button"
                    class="rounded border border-line-strong px-4 py-2.5 text-sm font-medium text-body transition hover:bg-sunk"
                    @click="again"
                >
                    Renew another car
                </button>
            </div>
        </div>
    </div>

    <div v-else class="mx-auto max-w-xl px-4 py-12">
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

            <p class="mt-1 text-lg font-semibold uppercase text-ink">
                {{ found.registration }}
                <span v-if="found.name" class="text-base font-normal normal-case text-muted">· {{ found.name }}</span>
            </p>

            <!-- Where the plan stands. Never a reason not to renew. -->
            <RenewalNotice :timing="found.timing" class="mt-4" />

            <!--
                What the price is made of.

                A total on its own is what people phone the office about, and
                the surcharge line in particular - it is the one figure a
                customer does not expect and would otherwise read as a mistake.
            -->
            <dl class="mt-4 flex flex-col gap-1.5 text-sm">
                <div v-for="(line, i) in found.lines" :key="i" class="flex items-baseline justify-between gap-4">
                    <dt class="text-muted">
                        {{ line.label }}
                        <span v-if="line.recurring && found.months > 1" class="text-xs text-faint">× {{ found.months }}</span>
                    </dt>
                    <dd class="shrink-0 tabular-nums" :class="line.amount < 0 ? 'text-ok' : 'text-body'">
                        {{ line.amount < 0 ? '−' : '' }}{{ money(Math.abs(line.amount)) }}
                    </dd>
                </div>

                <div class="mt-1 flex justify-between border-t border-line pt-2">
                    <dt class="text-muted">Status</dt>
                    <dd class="text-ink">{{ found.status }}</dd>
                </div>

                <div class="flex items-baseline justify-between">
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
                This price is worked out by us at today's rates, not by your browser, and it is the figure
                the payment window will ask for.
            </p>
        </div>

        <p v-if="problem" class="mt-4 rounded border border-crit bg-crit-soft px-4 py-3 text-sm text-crit">
            {{ problem }}
        </p>

        <p class="mt-8 text-sm text-muted">
            Not renewing?
            <RouterLink :to="{ name: 'subscribe' }" class="font-medium text-accent-ink hover:underline">
                Start a new plan
            </RouterLink>
            instead.
        </p>
    </div>
</template>
