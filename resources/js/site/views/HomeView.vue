<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { api } from '@/shared/api/client';

interface Banner {
    id: string;
    eyebrow: string | null;
    headline: string;
    subheadline: string | null;
    cta: { label: string; route: string } | null;
    image: string | null;
    secondary: { label: string; route: string } | null;
}

const { data } = useQuery({
    queryKey: ['public-catalogue'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});

/**
 * The headline comes from the database, so a festival offer can go up without
 * a release and take itself down on the right morning.
 */
const { data: content } = useQuery({
    queryKey: ['public-content'],
    queryFn: async () => (await api.get('/public/content')).data.data,
    staleTime: 5 * 60 * 1000,
});

const banner = computed<Banner | null>(() => content.value?.banners?.[0] ?? null);

/** The cheapest plan on sale, so the headline price is never out of date. */
const from = computed<number | null>(() => {
    const types = data.value?.vehicle_types ?? [];
    if (!types.length) return null;
    return Math.min(...types.map((t: { price: number }) => t.price));
});

const steps = [
    { title: 'Tell us where the car is', body: 'Society, block and flat, so the cleaner finds it first time.' },
    { title: 'Pick how often', body: 'Daily exterior as standard. Interior weekly, fortnightly, or not at all.' },
    { title: 'We come to you', body: 'The same cleaner each day, before you leave for work.' },
];
</script>

<template>
    <div>
        <section class="border-b border-line bg-surface">
            <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 md:grid-cols-2 md:items-center md:py-20">
                <div>
                    <p v-if="banner?.eyebrow" class="text-sm font-semibold uppercase tracking-widest text-accent">
                        {{ banner.eyebrow }}
                    </p>
                    <h1 class="mt-3 text-4xl font-bold leading-tight tracking-tight text-ink md:text-5xl" style="text-wrap: balance">
                        {{ banner?.headline ?? 'Doorstep car cleaning, every day.' }}
                    </h1>
                    <p v-if="banner?.subheadline" class="mt-4 max-w-prose text-lg text-body">
                        {{ banner.subheadline }}
                    </p>

                    <div class="mt-7 flex flex-wrap items-center gap-3">
                        <RouterLink
                            :to="{ name: banner?.cta?.route || 'subscribe' }"
                            class="rounded bg-accent px-6 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent"
                        >
                            {{ banner?.cta?.label ?? 'See your price' }}
                        </RouterLink>
                        <RouterLink
                            v-if="banner?.secondary"
                            :to="{ name: banner.secondary.route || 'packages' }"
                            class="rounded border border-line-strong px-6 py-3 text-sm font-semibold text-body transition hover:bg-sunk"
                        >
                            {{ banner.secondary.label }}
                        </RouterLink>
                    </div>

                    <p v-if="from !== null" class="mt-4 text-sm text-muted">
                        Plans from <strong class="tabular-nums text-ink">&#8377;{{ from }}</strong> a month.
                    </p>
                </div>

                <!--
                    The banner's own image when one is set, otherwise the three
                    steps. A home page with a picture of the actual service
                    says more than a list, but a missing file must not leave a
                    hole where the hero should be.
                -->
                <div v-if="banner?.image" class="overflow-hidden rounded-lg border border-line">
                    <img
                        :src="banner.image"
                        :alt="banner.headline"
                        class="h-full w-full object-cover"
                        loading="eager"
                    />
                </div>

                <div v-else class="rounded-lg border border-line bg-sunk p-6">
                    <ol class="flex flex-col gap-5">
                        <li v-for="(step, i) in steps" :key="step.title" class="flex gap-4">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-bold tabular-nums text-accent-ink">
                                {{ i + 1 }}
                            </span>
                            <span>
                                <span class="block font-semibold text-ink">{{ step.title }}</span>
                                <span class="block text-sm text-body">{{ step.body }}</span>
                            </span>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <section v-if="banner?.image" class="mx-auto max-w-6xl px-4 pt-12">
            <ol class="grid gap-5 sm:grid-cols-3">
                <li v-for="(step, i) in steps" :key="step.title" class="flex gap-4">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-bold tabular-nums text-accent-ink">
                        {{ i + 1 }}
                    </span>
                    <span>
                        <span class="block font-semibold text-ink">{{ step.title }}</span>
                        <span class="block text-sm text-body">{{ step.body }}</span>
                    </span>
                </li>
            </ol>
        </section>

        <section class="mx-auto max-w-6xl px-4 py-14">
            <h2 class="text-2xl font-bold tracking-tight text-ink">What it costs</h2>
            <p class="mt-1 max-w-prose text-body">
                The price depends on the size of your car, the package, how often the inside is done,
                and how long you sign up for. Longer plans cost less per month.
            </p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="type in data?.vehicle_types ?? []"
                    :key="type.id"
                    class="rounded-lg border border-line bg-surface p-4"
                >
                    <h3 class="font-semibold text-ink">{{ type.name }}</h3>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink">&#8377;{{ type.price }}</p>
                    <p class="text-xs text-muted">base, per month</p>
                </div>
            </div>

            <p class="mt-6 text-sm text-muted">
                Your society may add a small surcharge. It is shown before you pay, never after.
            </p>
        </section>
    </div>
</template>
