<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { api } from '@/shared/api/client';

/**
 * What each package includes.
 *
 * The bullets arrive as plain text, taken apart on the server. v1 stored these
 * as editor HTML and rendered it raw, which would let anyone able to edit a
 * package run script on this page.
 */
const { data, isPending } = useQuery({
    queryKey: ['public-catalogue'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-10">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Packages</h1>
        <p class="mt-1 max-w-prose text-body">
            Every plan includes a daily exterior clean. The package sets what else is done, and how often.
        </p>

        <p v-if="isPending" class="mt-8 text-muted">Loading…</p>

        <div v-else class="mt-6 grid gap-4 md:grid-cols-3">
            <article
                v-for="pkg in data?.packages ?? []"
                :key="pkg.id"
                class="flex flex-col rounded-lg border border-line bg-surface p-5"
            >
                <h2 class="text-lg font-semibold text-ink">{{ pkg.name }}</h2>
                <p class="mt-1 text-sm text-muted">
                    <template v-if="pkg.price > 0">
                        <strong class="tabular-nums text-ink">+&#8377;{{ pkg.price }}</strong> a month
                    </template>
                    <template v-else>No extra charge</template>
                </p>

                <div v-for="(section, i) in pkg.sections" :key="i" class="mt-4">
                    <h3 v-if="section.heading" class="text-xs font-semibold uppercase tracking-wide text-accent">
                        {{ section.heading }}
                    </h3>
                    <ul class="mt-1.5 flex flex-col gap-1">
                        <li v-for="(item, j) in section.items" :key="j" class="flex gap-2 text-sm text-body">
                            <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-accent" aria-hidden="true" />
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <p v-if="!pkg.sections.length && pkg.summary" class="mt-3 text-sm text-body">{{ pkg.summary }}</p>

                <RouterLink
                    :to="{ name: 'subscribe' }"
                    class="mt-5 rounded bg-accent px-4 py-2 text-center text-sm font-semibold text-on-accent transition hover:brightness-110"
                >
                    Price this package
                </RouterLink>
            </article>
        </div>

        <section class="mt-10">
            <h2 class="text-xl font-bold tracking-tight text-ink">Interior cleaning</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div v-for="s in data?.service_types ?? []" :key="s.id" class="rounded border border-line bg-surface p-4">
                    <h3 class="font-medium text-ink">{{ s.name }}</h3>
                    <p class="mt-1 text-sm tabular-nums text-muted">
                        {{ s.price > 0 ? '+₹' + s.price + ' a month' : 'Included' }}
                    </p>
                </div>
            </div>
        </section>

        <section class="mt-10">
            <h2 class="text-xl font-bold tracking-tight text-ink">Signing up for longer</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div v-for="d in data?.durations ?? []" :key="d.id" class="rounded border border-line bg-surface p-4">
                    <h3 class="font-medium text-ink">{{ d.name }}</h3>
                    <p class="mt-1 text-sm tabular-nums" :class="d.discount > 0 ? 'text-ok' : 'text-muted'">
                        {{ d.discount > 0 ? 'Save ₹' + d.discount : 'No discount' }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
