<script setup lang="ts">
import RenewalNotice, { type RenewalTiming } from '@/shared/RenewalNotice.vue';

/**
 * One plan, priced, with the button that pays for it.
 *
 * Shared by the two ways a customer reaches a renewal without signing in:
 * typing a car number on /renew, and tapping Renew now in a WhatsApp message.
 * The same card for both on purpose - the price breakdown is the part people
 * phone the office about, and two copies of it is two things to keep in step.
 *
 * It renders and emits. What a plan costs, whether it can be paid for and what
 * happens when it is are all decided elsewhere.
 */

export interface RenewablePlan {
    subscription_id: string;
    registration: string;
    name: string;
    renews_on: string | null;
    status: string;
    amount: number;
    formatted: string;
    lines: { source: string; label: string; amount: number; recurring: boolean }[];
    months: number;
    timing: RenewalTiming;
}

defineProps<{
    plan: RenewablePlan;
    paying: boolean;
}>();

defineEmits<{ pay: [] }>();

const money = (rupees: number) =>
    new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);
</script>

<template>
    <div class="rounded-lg border border-line-strong bg-surface p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted">Your plan</p>

        <p class="mt-1 text-lg font-semibold uppercase text-ink">
            {{ plan.registration }}
            <span v-if="plan.name" class="text-base font-normal normal-case text-muted">· {{ plan.name }}</span>
        </p>

        <!-- Where the plan stands. Never a reason not to renew. -->
        <RenewalNotice :timing="plan.timing" class="mt-4" />

        <!--
            What the price is made of.

            A total on its own is what people phone the office about, and the
            surcharge line in particular - it is the one figure a customer does
            not expect and would otherwise read as a mistake.
        -->
        <dl class="mt-4 flex flex-col gap-1.5 text-sm">
            <div v-for="(line, i) in plan.lines" :key="i" class="flex items-baseline justify-between gap-4">
                <dt class="text-muted">
                    {{ line.label }}
                    <span v-if="line.recurring && plan.months > 1" class="text-xs text-faint">× {{ plan.months }}</span>
                </dt>
                <dd class="shrink-0 tabular-nums" :class="line.amount < 0 ? 'text-ok' : 'text-body'">
                    {{ line.amount < 0 ? '−' : '' }}{{ money(Math.abs(line.amount)) }}
                </dd>
            </div>

            <div class="mt-1 flex justify-between border-t border-line pt-2">
                <dt class="text-muted">Status</dt>
                <dd class="text-ink">{{ plan.status }}</dd>
            </div>

            <div class="flex items-baseline justify-between">
                <dt class="font-semibold text-ink">To pay</dt>
                <dd class="text-xl font-bold tabular-nums text-ink">{{ plan.formatted }}</dd>
            </div>
        </dl>

        <button
            type="button"
            :disabled="paying"
            class="mt-4 w-full rounded bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60"
            @click="$emit('pay')"
        >
            {{ paying ? 'Opening payment…' : 'Pay ' + plan.formatted }}
        </button>

        <p class="mt-2 text-xs text-faint">
            This price is worked out by us at today's rates, not by your browser, and it is the figure
            the payment window will ask for.
        </p>
    </div>
</template>
