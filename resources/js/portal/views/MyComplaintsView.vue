<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { describeError } from '@/shared/api/client';
import { fetchComplaintOptions, fetchMyComplaints, raiseComplaint, type Complaint } from '@/portal/complaints.api';
import { fetchOverview } from '@/portal/portal.api';

/**
 * Raising a complaint, and following what happened to it.
 *
 * The most common reason a customer signs in, and until now the only way to do
 * it was to telephone. What they get back is the promise the business made
 * about when it will be dealt with, so the page answers "when will somebody
 * look at this" without another call.
 */
const queryClient = useQueryClient();

const { data: complaints, isLoading, error } = useQuery({
    queryKey: ['portal', 'complaints'],
    queryFn: fetchMyComplaints,
});

const { data: options } = useQuery({
    queryKey: ['complaint-options'],
    queryFn: fetchComplaintOptions,
    staleTime: 10 * 60 * 1000,
});

/** Their cars, so a complaint can name the one it is about. */
const { data: overview } = useQuery({ queryKey: ['portal', 'overview'], queryFn: fetchOverview });

const raising = ref(false);
const busy = ref(false);
const problem = ref<string | null>(null);
const notice = ref<string | null>(null);

const form = ref({ category: '', description: '', vehicle_id: '' });

const vehicles = computed(() => overview.value?.vehicles ?? []);
const open = computed(() => (complaints.value ?? []).filter((c) => c.status.value !== 'closed'));
const settled = computed(() => (complaints.value ?? []).filter((c) => c.status.value === 'closed'));

function start() {
    problem.value = null;
    notice.value = null;
    raising.value = true;

    form.value = {
        category: options.value?.categories[0]?.value ?? '',
        description: '',
        // One car means there is nothing to choose.
        vehicle_id: vehicles.value.length === 1 ? vehicles.value[0].id : '',
    };
}

async function submit() {
    busy.value = true;
    problem.value = null;

    try {
        const complaint = await raiseComplaint({
            category: form.value.category,
            description: form.value.description,
            vehicle_id: form.value.vehicle_id || null,
        });

        await queryClient.invalidateQueries({ queryKey: ['portal', 'complaints'] });

        raising.value = false;
        notice.value = complaint.due_by
            ? `Raised as ${complaint.reference}. We will have looked at it by ${on(complaint.due_by)}.`
            : `Raised as ${complaint.reference}.`;
    } catch (e) {
        problem.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}

function on(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleString('en-IN', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    });
}

function tone(complaint: Complaint): string {
    if (complaint.status.value === 'closed') return 'bg-sunk text-muted';
    if (complaint.is_overdue) return 'bg-crit-soft text-crit';
    if (complaint.status.value === 'resolved') return 'bg-ok-soft text-ok';

    return 'bg-warn-soft text-warn';
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <header class="flex flex-wrap items-start gap-3">
            <div>
                <h1 class="text-xl font-semibold text-ink">Complaints</h1>
                <p class="text-sm text-muted">Tell us when something is not right.</p>
            </div>

            <button
                type="button"
                class="ms-auto rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110"
                @click="start"
            >
                Raise a complaint
            </button>
        </header>

        <p v-if="notice" class="rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">{{ notice }}</p>
        <p v-if="problem" class="rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">{{ problem }}</p>

        <p v-if="isLoading" class="text-sm text-muted">Loading…</p>

        <p v-else-if="error" class="rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
            {{ describeError(error).message }}
        </p>

        <template v-else>
            <p
                v-if="!open.length && !settled.length"
                class="rounded border border-line bg-surface px-4 py-6 text-center text-sm text-muted"
            >
                Nothing raised. If a clean was missed or something is not right, tell us here.
            </p>

            <section v-if="open.length" class="flex flex-col gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Being dealt with</h2>

                <article v-for="c in open" :key="c.id" class="rounded-lg border border-line-strong bg-surface p-4">
                    <div class="flex flex-wrap items-start gap-2">
                        <div>
                            <p class="font-medium text-ink">{{ c.category.label }}</p>
                            <p class="text-xs tabular-nums text-muted">
                                {{ c.reference }}<span v-if="c.vehicle"> · {{ c.vehicle.registration }}</span>
                            </p>
                        </div>

                        <span class="ms-auto rounded px-2 py-0.5 text-xs font-medium" :class="tone(c)">
                            {{ c.is_overdue ? 'Overdue' : c.status.label }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-body">{{ c.description }}</p>

                    <!-- The promise made when it was raised, which is what the
                         customer actually wants to know. -->
                    <p v-if="c.due_by" class="mt-2 text-xs" :class="c.is_overdue ? 'text-crit' : 'text-muted'">
                        {{ c.is_overdue ? 'We said we would look at this by' : 'We will have looked at this by' }}
                        {{ on(c.due_by) }}
                    </p>
                </article>
            </section>

            <section v-if="settled.length" class="flex flex-col gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Closed</h2>

                <article v-for="c in settled" :key="c.id" class="rounded-lg border border-line bg-surface p-3">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <p class="text-sm font-medium text-body">{{ c.category.label }}</p>
                        <p class="text-xs tabular-nums text-faint">{{ c.reference }}</p>
                        <p class="ms-auto text-xs text-muted">{{ on(c.created_at) }}</p>
                    </div>
                </article>
            </section>
        </template>

        <!-- The form -->
        <div v-if="raising" class="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-black/40 p-4" @click.self="raising = false">
            <form class="w-full max-w-md rounded-lg border border-line-strong bg-surface p-5 shadow-xl" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-ink">What has gone wrong?</h2>

                <label class="mb-3 block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">What is it about</span>
                    <select v-model="form.category" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option v-for="c in options?.categories ?? []" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                </label>

                <label v-if="vehicles.length > 1" class="mb-3 block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Which car</span>
                    <select v-model="form.vehicle_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="">Not about one car</option>
                        <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.registration }}</option>
                    </select>
                </label>

                <label class="mb-3 block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Tell us what happened</span>
                    <textarea
                        v-model.trim="form.description"
                        rows="4"
                        required
                        minlength="5"
                        maxlength="2000"
                        placeholder="The car was not cleaned on Tuesday or Wednesday."
                        class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    ></textarea>
                    <span class="mt-1 block text-xs text-faint">
                        The date and what you saw help most. We will come back to you with a time.
                    </span>
                </label>

                <p v-if="problem" class="mb-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ problem }}</p>

                <div class="flex gap-2">
                    <button type="submit" :disabled="busy" class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60">
                        {{ busy ? 'Sending…' : 'Send it' }}
                    </button>
                    <button type="button" class="rounded border border-line-strong px-4 py-2 text-sm text-body transition hover:bg-sunk" @click="raising = false">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
