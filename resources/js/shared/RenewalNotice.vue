<script setup lang="ts">
import { computed } from 'vue';
import type { RenewalTiming } from '@/shared/types';

/**
 * Where a plan stands against its own end date, said the same way everywhere.
 *
 * Four screens offer a renewal - the public page, the customer's own pages, and
 * the office list for both administrators and franchise owners - and before
 * this they said nothing at all about timing. Somebody renewing three weeks
 * early had no way to know whether they were about to throw those three weeks
 * away, which is the kind of doubt that makes a person close the tab and mean
 * to phone tomorrow.
 *
 * Nothing here blocks anything. Renewing early is a perfectly good thing for a
 * customer to do and the server extends from the end date when it can, so the
 * notice exists to say so, not to stand in the way. The one case that is worth
 * a warning colour is a plan already past its date, because that one is costing
 * the customer service days.
 */
/*
 * Defined once beside the rest of the API shapes and re-exported here, so a
 * screen importing this component gets the type with it rather than importing
 * the same idea from two places.
 */
export type { RenewalTiming } from '@/shared/types';

const props = withDefaults(
    defineProps<{
        timing: RenewalTiming | null | undefined;
        /**
         * Who is reading it.
         *
         * The office is told what the system will do; the customer is told what
         * it means for them. Same facts, and the office does not need reassuring
         * that their own money is safe.
         */
        audience?: 'customer' | 'office';
    }>(),
    { audience: 'customer' },
);

const day = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

/** "in 34 days" reads better than "34 days remaining" in a sentence. */
const days = computed(() => {
    const n = props.timing?.early ? props.timing.days_remaining ?? 0 : props.timing?.days_overdue ?? 0;
    return n === 1 ? '1 day' : `${n} days`;
});

const message = computed<{ tone: 'info' | 'warn'; title: string; body: string } | null>(() => {
    const t = props.timing;

    if (!t || t.renews_on === null) return null;

    /*
     * What renewing now would actually do to the dates, said in the words the
     * reader needs rather than as a rule.
     *
     * Taken from the server, never guessed from `overdue`. Whether a lapsed
     * plan carries on from its old date or begins again today depends on how
     * long a term is, which this component has no way to know: a monthly plan
     * a week late carries on, the same plan a year late starts afresh. This
     * said "restarts it from today" for both of them until the server began
     * sending the answer.
     */
    const carriesOn = t.starts_from === 'end_date';
    const from = day(t.next_period_start);
    const to = day(t.next_period_end);

    if (t.overdue) {
        return {
            tone: 'warn',
            title: `This plan ran out on ${day(t.renews_on)}, ${days.value} ago.`,
            body: carriesOn
                ? (props.audience === 'office'
                    ? `Renewing carries on from ${from}, not from today — the new term runs to ${to}.`
                    : `Renewing carries straight on from ${from}, so there is no gap to pay for twice. The new term runs to ${to}.`)
                : (props.audience === 'office'
                    ? `Too long lapsed to carry on. Renewing starts a fresh term today, running to ${to}.`
                    : `It has been too long to carry on from the old date, so renewing starts a fresh term today, running to ${to}. Cleaning resumes on the next round.`),
        };
    }

    if (t.due_today) {
        return {
            tone: 'info',
            title: 'This plan runs out today.',
            body: props.audience === 'office'
                ? `Renewing now continues it without a gap, to ${to}.`
                : `Renew now and there is no break in the cleaning. The new term runs to ${to}.`,
        };
    }

    if (t.early) {
        return {
            tone: 'info',
            title: `Already paid up to ${day(t.renews_on)} — ${days.value} still to run.`,
            body: props.audience === 'office'
                ? `Renewing now is allowed. The new term is added on from ${from}, not started from today, and runs to ${to}.`
                : `You do not have to renew yet. If you do, the ${days.value} you have already paid for are not lost — the new term starts on ${from} and runs to ${to}.`,
        };
    }

    return null;
});
</script>

<template>
    <div
        v-if="message"
        class="rounded-lg border px-4 py-3 text-sm"
        :class="message.tone === 'warn'
            ? 'border-warn bg-warn-soft text-warn'
            : 'border-line-strong bg-sunk text-body'"
        role="note"
    >
        <p class="font-medium" :class="message.tone === 'warn' ? 'text-warn' : 'text-ink'">
            {{ message.title }}
        </p>
        <p class="mt-0.5">{{ message.body }}</p>
    </div>
</template>
