<script setup lang="ts">
import { ref, watch } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { fetchOverview, saveProfile } from '@/portal/portal.api';
import { describeError } from '@/shared/api/client';

/**
 * The customer's own details.
 *
 * Sector and society are shown but not editable. Where somebody lives decides
 * which franchise services them and what the plan costs, so moving between
 * them is a conversation with the office, not a dropdown on a Sunday night.
 */
const queryClient = useQueryClient();

const { data } = useQuery({ queryKey: ['portal', 'overview'], queryFn: fetchOverview });

const form = ref({ name: '', email: '', house_no: '', address: '', preferred_time: '' });
const saving = ref(false);
const saved = ref(false);
const error = ref<string | null>(null);

watch(
    () => data.value?.profile,
    (profile) => {
        if (!profile) return;

        form.value = {
            name: profile.name ?? '',
            email: profile.email ?? '',
            house_no: profile.house_no ?? '',
            address: profile.address ?? '',
            // The server sends seconds; the time input will not accept them.
            preferred_time: (profile.preferred_time ?? '').slice(0, 5),
        };
    },
    { immediate: true },
);

async function save() {
    saving.value = true;
    saved.value = false;
    error.value = null;

    try {
        await saveProfile({
            name: form.value.name,
            email: form.value.email || null,
            house_no: form.value.house_no || null,
            address: form.value.address || null,
            preferred_time: form.value.preferred_time || null,
        });

        await queryClient.invalidateQueries({ queryKey: ['portal'] });
        saved.value = true;
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="save">
        <header>
            <h1 class="text-xl font-semibold text-ink">My details</h1>
            <p class="text-sm text-muted">What we call you and where we come to.</p>
        </header>

        <p v-if="error" class="rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">{{ error }}</p>
        <p v-else-if="saved" class="rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">Saved.</p>

        <div class="grid gap-3 rounded-lg border border-line-strong bg-surface p-4 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Name</span>
                <input v-model.trim="form.name" type="text" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Email</span>
                <input v-model.trim="form.email" type="email" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">House number</span>
                <input v-model.trim="form.house_no" type="text" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Preferred time</span>
                <input v-model="form.preferred_time" type="time" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </label>

            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Address</span>
                <textarea v-model.trim="form.address" rows="2" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"></textarea>
            </label>
        </div>

        <!--
            Read only, and said plainly rather than shown greyed out with no
            explanation: these decide who services the car and what it costs.
        -->
        <div class="grid gap-3 rounded-lg border border-line bg-sunk p-4 sm:grid-cols-2">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted">Sector</p>
                <p class="text-sm text-body">{{ data?.profile.sector ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted">Society</p>
                <p class="text-sm text-body">{{ data?.profile.society ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted">Phone</p>
                <p class="text-sm tabular-nums text-body">{{ data?.profile.phone ?? '—' }}</p>
            </div>
            <p class="text-xs text-faint sm:col-span-2">
                Moving to another sector or society changes who services your car and what the plan costs,
                so please call the office to have it changed. Your phone number is how you sign in.
            </p>
        </div>

        <div>
            <button
                type="submit"
                class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:opacity-90 disabled:opacity-60"
                :disabled="saving"
            >
                {{ saving ? 'Saving…' : 'Save changes' }}
            </button>
        </div>
    </form>
</template>
