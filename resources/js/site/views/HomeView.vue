<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import BannerSlider, { type Banner } from '@/site/BannerSlider.vue';

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

/**
 * Every live banner, in the order the master gives them.
 *
 * The slider handles none, one and many - so an office that has not set any up
 * still gets a working home page with the default headline.
 */
const banners = computed<Banner[]>(() => content.value?.banners ?? []);

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
        <BannerSlider :banners="banners" :price-from="from">
            <!--
                Shown in place of the picture when no live banner has one. A
                home page with nothing beside the headline looks broken, and the
                three steps are the next most useful thing to put there.
            -->
            <template #aside>
                <div class="rounded-lg border border-line bg-sunk p-6">
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
            </template>
        </BannerSlider>

        <section v-if="banners.some((b) => b.image)" class="mx-auto max-w-6xl px-4 pt-12">
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
