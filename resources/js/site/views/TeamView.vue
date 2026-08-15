<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';

const { data, isPending } = useQuery({
    queryKey: ['public-team'],
    queryFn: async () => (await api.get('/public/team')).data.data,
    staleTime: 10 * 60 * 1000,
});

/** Initials, so a member with no photo still gets something to sit behind. */
function initials(name: string): string {
    return name.split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
}
</script>

<template>
    <div class="mx-auto max-w-4xl px-4 py-10">
        <h1 class="text-2xl font-bold tracking-tight text-ink">The people who do it</h1>
        <p class="mt-1 max-w-prose text-body">
            The same cleaner comes to your car each day. These are the people running it.
        </p>

        <p v-if="isPending" class="mt-10 text-muted">Loading…</p>

        <p v-else-if="!data?.length" class="mt-10 rounded border border-line bg-surface px-4 py-8 text-center text-muted">
            Team details are being put together.
        </p>

        <div v-else class="mt-8 grid gap-5 sm:grid-cols-2">
            <article v-for="member in data" :key="member.id" class="flex gap-4 rounded-lg border border-line bg-surface p-5">
                <span
                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent-soft text-lg font-bold text-accent-ink"
                    aria-hidden="true"
                >
                    {{ initials(member.name) }}
                </span>
                <div>
                    <h2 class="font-semibold text-ink">{{ member.name }}</h2>
                    <p v-if="member.title" class="text-sm text-accent">{{ member.title }}</p>
                    <p v-if="member.bio" class="mt-1.5 text-sm text-body">{{ member.bio }}</p>
                </div>
            </article>
        </div>
    </div>
</template>
