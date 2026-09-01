<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { api } from '@/shared/api/client';
import BannerSlider, { type Banner } from '@/site/BannerSlider.vue';

/**
 * The home page.
 *
 * One job: get somebody from "what is this" to a price in as few movements as
 * possible. Everything here is either the offer, the proof, or the price - and
 * anything that is none of those has been left off.
 */
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

/**
 * The questions the office already answers, shown here as well as on their own
 * page - and the same ones the server puts in the page's structured data.
 *
 * Six, because a home page is not a manual: enough to answer what a person
 * hesitates over, with a way through to the rest.
 */
const faqs = computed<Array<{ id: string; question: string; answer: string }>>(
    () => (content.value?.faqs ?? []).slice(0, 6),
);

const steps = [
    { title: 'Tell us where the car is', body: 'Society, block and flat, so the cleaner finds it first time.' },
    { title: 'Pick how often', body: 'Daily exterior as standard. Interior weekly, fortnightly, or not at all.' },
    { title: 'We come to you', body: 'The same cleaner each day, before you leave for work.' },
];

/*
 * Three promises, each of them something the system actually does.
 *
 * Deliberately not "10,000 happy customers" or a star rating: an unverifiable
 * number on a home page is a claim somebody has to stand behind, and these are
 * all things a customer can check by using the service for a week.
 */
const promises = [
    {
        title: 'The same cleaner every day',
        body: 'Not a different person each morning. Yours is named on your plan, and you are told if it changes.',
        icon: 'M10 9.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM4.5 16.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5',
    },
    {
        title: 'You hear what happened',
        body: 'A message each evening telling you which of your cars were cleaned — and, on the days it could not be done, why.',
        icon: 'M3 5.5h14v9H9l-4 3v-3H3z',
    },
    {
        title: 'The price before you pay',
        body: 'Worked out by us, society surcharge included, and shown in full on the form. Never a figure that changes at the end.',
        icon: 'M6 4h8M6 7.5h8M12 4c2.5 0 3.2 3.5 0 3.5H6l6 8.5',
    },
];

const money = (value: number) =>
    new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value);
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
                <div class="rounded-xl border border-line bg-sunk/60 p-6 backdrop-blur">
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

        <!--
            The three promises, immediately under the hero.

            This is the band that answers "why you" before somebody scrolls
            looking for a price, and every line in it is checkable.
        -->
        <section class="border-b border-line bg-sunk/40">
            <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-3 md:py-14">
                <div v-for="promise in promises" :key="promise.title" class="flex gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent-ink">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path :d="promise.icon" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <div>
                        <h2 class="font-semibold text-ink">{{ promise.title }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-body">{{ promise.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!--
            The steps again in a row, but only when the hero is carrying an
            image - otherwise they are already beside the headline and would be
            said twice on one screen.
        -->
        <section v-if="banners.some((b) => b.image)" class="mx-auto max-w-6xl px-4 pt-16 sm:px-6">
            <p class="text-sm font-semibold uppercase tracking-widest text-accent">How it works</p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Three steps, then it just happens</h2>

            <ol class="mt-8 grid gap-6 sm:grid-cols-3">
                <li
                    v-for="(step, i) in steps"
                    :key="step.title"
                    class="relative rounded-xl border border-line bg-surface p-5"
                >
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent text-sm font-bold tabular-nums text-on-accent">
                        {{ i + 1 }}
                    </span>
                    <h3 class="mt-4 font-semibold text-ink">{{ step.title }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-body">{{ step.body }}</p>
                </li>
            </ol>
        </section>

        <!-- What it costs -->
        <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-widest text-accent">What it costs</p>
                    <h2 class="mt-2 text-2xl font-bold tracking-tight text-ink sm:text-3xl">
                        Priced by the size of your car
                    </h2>
                    <p class="mt-2 max-w-prose text-body">
                        Then adjusted for the package, how often the inside is done, and how long you sign
                        up for. Longer plans cost less per month.
                    </p>
                </div>

                <RouterLink
                    :to="{ name: 'packages' }"
                    class="rounded-lg border border-line-strong px-5 py-2.5 text-sm font-semibold text-body transition hover:bg-sunk"
                >
                    See what is in each package
                </RouterLink>
            </div>

            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <!--
                    A card per car size, linking straight into the form. The
                    price is the base rate, said plainly rather than dressed as
                    a total somebody would then find was different.
                -->
                <RouterLink
                    v-for="type in data?.vehicle_types ?? []"
                    :key="type.id"
                    :to="{ name: 'subscribe' }"
                    class="group rounded-xl border border-line bg-surface p-5 transition hover:border-accent hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    <h3 class="font-semibold text-ink">{{ type.name }}</h3>

                    <p class="mt-2 flex items-baseline gap-1.5">
                        <span class="text-3xl font-bold tabular-nums tracking-tight text-ink">{{ money(type.price) }}</span>
                        <span class="text-sm text-muted">/ month</span>
                    </p>

                    <p class="mt-1 text-xs text-muted">base rate, before package and duration</p>

                    <span class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-accent-ink">
                        Get my price
                        <svg class="h-4 w-4 transition group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="m8 4 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                </RouterLink>
            </div>

            <p class="mt-6 text-sm text-muted">
                Your society may add a small surcharge. It is shown before you pay, never after.
            </p>
        </section>

        <!--
            The questions, on the page a search engine is most likely to land
            on. These are the same ones the server puts in the page's FAQ
            structured data, so what Google shows and what a visitor reads can
            never disagree.
        -->
        <section v-if="faqs.length" class="border-y border-line bg-sunk/40">
            <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
                <p class="text-sm font-semibold uppercase tracking-widest text-accent">Before you ask</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Common questions</h2>

                <div class="mt-8 divide-y divide-line rounded-xl border border-line bg-surface">
                    <details v-for="faq in faqs" :key="faq.id" class="group px-5 py-4">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-ink">
                            {{ faq.question }}
                            <svg
                                class="h-4 w-4 shrink-0 text-muted transition group-open:rotate-180"
                                viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"
                            >
                                <path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </summary>

                        <!-- Plain text, as the questions page renders them.
                             The answers are written as prose in the office and
                             are not markup, so nothing here interprets them as
                             any. -->
                        <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-body">{{ faq.answer }}</p>
                    </details>
                </div>

                <RouterLink :to="{ name: 'faq' }" class="mt-6 inline-block text-sm font-medium text-accent-ink hover:underline">
                    All questions
                </RouterLink>
            </div>
        </section>

        <!-- The last thing on the page is the thing the page is for. -->
        <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="rounded-2xl border border-line-strong bg-gradient-to-br from-accent-soft via-surface to-surface p-8 sm:p-12">
                <h2 class="max-w-xl text-2xl font-bold tracking-tight text-ink sm:text-3xl" style="text-wrap: balance">
                    Wake up to a clean car tomorrow morning.
                </h2>

                <p class="mt-3 max-w-prose text-body">
                    Tell us where the car is kept and see the price straight away. Nothing is charged until
                    you have seen the total.
                </p>

                <div class="mt-7 flex flex-wrap gap-3">
                    <RouterLink
                        :to="{ name: 'subscribe' }"
                        class="rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        See your price
                    </RouterLink>

                    <RouterLink
                        :to="{ name: 'renew' }"
                        class="rounded-lg border border-line-strong bg-surface px-6 py-3 text-sm font-semibold text-body transition hover:bg-sunk"
                    >
                        Renew an existing plan
                    </RouterLink>
                </div>
            </div>
        </section>
    </div>
</template>
