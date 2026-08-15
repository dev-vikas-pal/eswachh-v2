<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { fetchOverview, type PortalPlan } from '@/portal/portal.api';
import { payForRenewal } from '@/shared/api/checkout';
import { describeError } from '@/shared/api/client';
import SubscriptionPaymentsPanel from '@/shared/SubscriptionPaymentsPanel.vue';

/**
 * What a customer signs in to see: their car, their plan, when it runs out.
 *
 * The renew button is the point of the page. Almost every payment a customer
 * makes after the first is a renewal, and in v1 they had to phone the office
 * to make one.
 */
const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['portal', 'overview'],
    queryFn: fetchOverview,
});

const busy = ref<string | null>(null);
const problem = ref<string | null>(null);
const outcome = ref<string | null>(null);

/** Which plan has its payment history open. */
const historyFor = ref<{ id: string; car: string | null } | null>(null);

const plans = computed(() => data.value?.plans ?? []);

function daysLeft(plan: PortalPlan): number | null {
    if (!plan.period.end) return null;

    const end = new Date(plan.period.end + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.round((end.getTime() - today.getTime()) / 86_400_000);
}

/** How the plan should read at a glance, in the customer's terms. */
function standing(plan: PortalPlan): { label: string; tone: string } {
    const left = daysLeft(plan);

    if (plan.status.value === 'ended') return { label: 'Ended', tone: 'text-muted' };
    if (plan.is_expired || (left !== null && left < 0)) return { label: 'Expired', tone: 'text-bad' };
    if (plan.status.value === 'hold') return { label: 'On hold', tone: 'text-warn' };
    if (plan.status.value === 'pending') return { label: 'Awaiting payment', tone: 'text-warn' };
    if (left !== null && left <= 14) return { label: `Renews in ${left} day${left === 1 ? '' : 's'}`, tone: 'text-warn' };

    return { label: 'Running', tone: 'text-ok' };
}

async function renew(plan: PortalPlan) {
    busy.value = plan.id;
    problem.value = null;
    outcome.value = null;

    /*
     * Through the shared checkout, not a dialog of this page's own. It handles
     * the machine with no gateway configured, posts the result to the callback
     * that verifies the signature, and words a failed callback carefully -
     * money may well have left the account even when the last step failed.
     */
    const result = await payForRenewal(plan.id, {
        name: data.value?.profile.name ?? '',
        email: data.value?.profile.email ?? '',
        phone: data.value?.profile.phone ?? '',
    });

    busy.value = null;

    if (result.ok) {
        outcome.value = result.message;
        await refetch();
        return;
    }

    // A cancelled dialog is not a problem, so it does not get the red box.
    if (result.cancelled) {
        outcome.value = result.message;
        return;
    }

    problem.value = result.message;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <header>
            <h1 class="text-xl font-semibold text-ink">
                Hello{{ data?.profile.name ? `, ${data.profile.name.split(' ')[0]}` : '' }}
            </h1>
            <p class="text-sm text-muted">Your cars and what is running on them.</p>
        </header>

        <p v-if="isLoading" class="text-sm text-muted">Loading…</p>

        <p v-else-if="error" class="rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
            {{ describeError(error).message }}
        </p>

        <template v-else>
            <p v-if="problem" class="rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
                {{ problem }}
            </p>

            <p v-else-if="outcome" class="rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">
                {{ outcome }}
            </p>

            <p v-if="!plans.length" class="rounded border border-line bg-surface px-4 py-6 text-center text-sm text-muted">
                There is nothing on your account yet. Once the office sets up your plan it will appear here.
            </p>

            <article
                v-for="plan in plans"
                :key="plan.id"
                class="rounded-lg border border-line-strong bg-surface p-4"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <div>
                        <h2 class="text-lg font-semibold uppercase tracking-wide text-ink">
                            {{ plan.vehicle?.registration ?? 'Car' }}
                        </h2>
                        <p class="text-sm text-muted">
                            {{ [plan.package, plan.service_type, plan.duration].filter(Boolean).join(' · ') || '—' }}
                        </p>
                    </div>

                    <p class="ms-auto text-sm font-medium" :class="standing(plan).tone">
                        {{ standing(plan).label }}
                    </p>
                </div>

                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted">Started</dt>
                        <dd class="tabular-nums text-body">{{ plan.period.start ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted">Runs until</dt>
                        <dd class="tabular-nums text-body">{{ plan.period.end ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted">Amount</dt>
                        <dd class="tabular-nums text-body">{{ plan.amount.formatted }}</dd>
                    </div>
                    <div v-if="plan.cloth.enabled">
                        <dt class="text-xs uppercase tracking-wide text-muted">Cloths left</dt>
                        <dd class="tabular-nums text-body">{{ plan.cloth.balance }}</dd>
                    </div>
                    <div v-else>
                        <dt class="text-xs uppercase tracking-wide text-muted">Cleaner</dt>
                        <dd class="text-body">{{ plan.vehicle?.cleaner?.name ?? 'Not assigned yet' }}</dd>
                    </div>
                </dl>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                    <button
                        v-if="plan.status.value !== 'ended'"
                        type="button"
                        class="rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:opacity-90 disabled:opacity-60"
                        :disabled="busy === plan.id"
                        @click="renew(plan)"
                    >
                        {{ busy === plan.id ? 'Opening…' : 'Renew this plan' }}
                    </button>

                    <!--
                        A plan renewed several times has several payments behind
                        one figure, and "what have I actually paid you" is the
                        second thing a customer asks after "when does it run out".
                    -->
                    <button
                        type="button"
                        class="text-sm text-accent underline-offset-2 hover:underline"
                        @click="historyFor = { id: plan.id, car: plan.vehicle?.registration ?? null }"
                    >
                        View payment history
                    </button>

                    <p class="text-xs text-faint">
                        Paid so far: {{ plan.paid.formatted }} of {{ plan.amount.formatted }}
                    </p>
                </div>
            </article>
        </template>

        <SubscriptionPaymentsPanel
            v-if="historyFor"
            :subscription-id="historyFor.id"
            :registration="historyFor.car"
            @close="historyFor = null"
        />
    </div>
</template>
