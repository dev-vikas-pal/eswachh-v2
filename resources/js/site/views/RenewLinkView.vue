<script setup lang="ts">
import { onMounted } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import RenewPlanCard from '@/site/RenewPlanCard.vue';
import { useRenewLink } from '@/site/renewLink';

/**
 * Where the Renew now button in a WhatsApp message lands.
 *
 * Everything is decided in site/renewLink.ts; this binds and renders.
 */
const route = useRoute();

const { loading, paying, plan, dead, problem, paid, load, pay } = useRenewLink(
    String(route.params.token ?? ''),
);

onMounted(load);

const money = (rupees: number) =>
    new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);

const day = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <!-- Paid. Nothing else on the page, as on the car-number renewal. -->
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

            <a
                href="/login"
                class="mt-4 inline-block rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent transition hover:brightness-110"
            >
                See my plan
            </a>
        </div>
    </div>

    <div v-else class="mx-auto max-w-xl px-4 py-12">
        <!--
            Looking it up.

            Said out loud rather than shown as an empty page: this is opened by
            tapping a button in WhatsApp, often on a phone on a slow connection,
            and a blank screen reads as a broken link.
        -->
        <div v-if="loading" class="rounded-lg border border-line bg-surface p-6 text-center">
            <p class="text-body">Finding your plan…</p>
        </div>

        <!--
            A link that no longer resolves.

            Never a dead end: the car-number page can still find the plan, and
            somebody who tapped a renewal button is somebody trying to pay.
        -->
        <div v-else-if="dead">
            <h1 class="text-2xl font-bold tracking-tight text-ink">This link has expired</h1>
            <p class="mt-2 text-body">{{ dead }}</p>

            <RouterLink
                :to="{ name: 'renew' }"
                class="mt-5 inline-block rounded bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110"
            >
                Renew with my car number
            </RouterLink>
        </div>

        <template v-else-if="plan">
            <h1 class="text-2xl font-bold tracking-tight text-ink">
                Renew your plan<span v-if="plan.name">, {{ plan.name.split(' ')[0] }}</span>
            </h1>
            <p class="mt-1 text-body">
                Everything is filled in already. Check the price and tap pay — there is nothing to sign in to.
            </p>

            <RenewPlanCard :plan="plan" :paying="paying" class="mt-6" @pay="pay" />

            <p v-if="problem" class="mt-4 rounded border border-crit bg-crit-soft px-4 py-3 text-sm text-crit">
                {{ problem }}
            </p>

            <p class="mt-8 text-sm text-muted">
                Renewing a different car?
                <RouterLink :to="{ name: 'renew' }" class="font-medium text-accent-ink hover:underline">
                    Look it up by number
                </RouterLink>
                instead.
            </p>
        </template>
    </div>
</template>
