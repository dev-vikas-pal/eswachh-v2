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
                Laid over the hero's picture: what the daily message looks like.

                It is the promise further down the page - "you hear what
                happened" - shown rather than described, and it is labelled as
                an example because that is what it is. Nobody's real car, and
                not a claim about a number of customers.
            -->
            <template #over>
                <figure class="card absolute -bottom-6 left-3 w-[17.5rem] max-w-[calc(100%-1.5rem)] p-4 sm:-left-6 sm:bottom-8 sm:p-5">
                    <figcaption class="flex items-center justify-between gap-3 text-[0.6875rem] font-bold uppercase tracking-widest text-muted">
                        Your daily update
                        <span class="rounded-full bg-sunk px-2 py-0.5 font-semibold normal-case tracking-normal text-muted">Example</span>
                    </figcaption>

                    <div class="mt-3 flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-ok-soft text-ok">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                                <path d="m4 10.5 4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <p class="text-sm leading-snug text-body">
                            <strong class="block font-semibold text-ink">UP16 AB 1234 was cleaned</strong>
                            This morning at 6:42, by your regular cleaner.
                        </p>
                    </div>
                </figure>
            </template>
        </BannerSlider>

        <!--
            The three promises, immediately under the hero.

            This is the band that answers "why you" before somebody scrolls
            looking for a price, and every line in it is checkable.
        -->
        <section class="mx-auto max-w-7xl px-4 pb-6 pt-10 sm:px-6 lg:px-8">
            <div class="grid gap-4 md:grid-cols-3">
                <div v-for="promise in promises" :key="promise.title" class="card p-6 sm:p-7">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-accent-soft text-accent-ink">
                        <svg class="h-6 w-6" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path :d="promise.icon" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <h2 class="mt-5 text-lg font-semibold text-ink">{{ promise.title }}</h2>
                    <p class="mt-2 text-[0.9375rem] leading-relaxed text-body">{{ promise.body }}</p>
                </div>
            </div>
        </section>

        <!--
            How it works. Numbered because it is a sequence: the three things
            happen in this order, and the third does not happen without the
            first two.
        -->
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
            <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16">
                <div>
                    <p class="eyebrow">How it works</p>
                    <h2 class="display section-title mt-4">Three steps, then it just happens.</h2>
                    <p class="mt-4 max-w-md leading-relaxed text-body">
                        Setting up takes about three minutes. After that there is nothing to book and nothing
                        to remember — the car is simply clean when you come down to it.
                    </p>
                    <RouterLink :to="{ name: 'subscribe' }" class="btn-primary mt-8">Start now</RouterLink>
                </div>

                <ol class="relative flex flex-col gap-4">
                    <li
                        v-for="(step, i) in steps"
                        :key="step.title"
                        class="card flex items-start gap-5 p-6"
                    >
                        <span class="display text-4xl leading-none tabular-nums text-accent">0{{ i + 1 }}</span>
                        <div>
                            <h3 class="text-lg font-semibold text-ink">{{ step.title }}</h3>
                            <p class="mt-1 leading-relaxed text-body">{{ step.body }}</p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <!-- What it costs -->
        <section class="border-y border-line bg-surface">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <p class="eyebrow">What it costs</p>
                        <h2 class="display section-title mt-4">Priced by the size of your car.</h2>
                        <p class="mt-4 max-w-prose leading-relaxed text-body">
                            Then adjusted for the package, how often the inside is done, and how long you sign
                            up for. Longer plans cost less per month.
                        </p>
                    </div>

                    <RouterLink :to="{ name: 'packages' }" class="btn-quiet">
                        See what is in each package
                    </RouterLink>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <!--
                        A card per car size, linking straight into the form.
                        The price is the base rate, said plainly rather than
                        dressed as a total somebody would then find was
                        different.
                    -->
                    <RouterLink
                        v-for="type in data?.vehicle_types ?? []"
                        :key="type.id"
                        :to="{ name: 'subscribe' }"
                        class="group flex flex-col rounded-[1.25rem] border border-line bg-ground p-6 transition duration-200 hover:-translate-y-1 hover:border-accent hover:bg-surface hover:shadow-[var(--lift-strong)]"
                    >
                        <h3 class="text-sm font-bold uppercase tracking-widest text-muted">{{ type.name }}</h3>

                        <p class="mt-4 flex items-baseline gap-2">
                            <span class="display text-5xl tabular-nums text-ink">{{ money(type.price) }}</span>
                            <span class="text-sm text-muted">/ month</span>
                        </p>

                        <p class="mt-2 text-sm text-muted">Base rate, before package and duration.</p>

                        <span class="mt-6 inline-flex items-center gap-1.5 border-t border-line pt-4 text-sm font-semibold text-accent-ink">
                            Get my price
                            <svg class="h-4 w-4 transition group-hover:translate-x-1" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M4 10h12m-5-5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                    </RouterLink>
                </div>

                <p class="mt-6 text-sm text-muted">
                    Your society may add a small surcharge. It is shown before you pay, never after.
                </p>
            </div>
        </section>

        <!--
            The questions, on the page a search engine is most likely to land
            on. These are the same ones the server puts in the page's FAQ
            structured data, so what Google shows and what a visitor reads can
            never disagree.
        -->
        <section v-if="faqs.length" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
            <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16">
                <div>
                    <p class="eyebrow">Before you ask</p>
                    <h2 class="display section-title mt-4">Common questions.</h2>
                    <p class="mt-4 max-w-md leading-relaxed text-body">
                        The things people most often want to know before starting a plan.
                    </p>
                    <RouterLink :to="{ name: 'faq' }" class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-accent-ink hover:underline">
                        All questions
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M4 10h12m-5-5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </RouterLink>
                </div>

                <div class="card divide-y divide-line overflow-hidden">
                    <details v-for="faq in faqs" :key="faq.id" class="group px-6 py-5 open:bg-sunk/50">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-semibold text-ink [&::-webkit-details-marker]:hidden">
                            {{ faq.question }}
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-line-strong text-muted transition group-open:rotate-45 group-open:border-accent group-open:text-accent-ink">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M10 4v12M4 10h12" stroke-linecap="round" />
                                </svg>
                            </span>
                        </summary>

                        <!-- Plain text, as the questions page renders them.
                             The answers are written as prose in the office and
                             are not markup, so nothing here interprets them as
                             any. -->
                        <p class="mt-3 max-w-prose whitespace-pre-line leading-relaxed text-body">{{ faq.answer }}</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- The last thing on the page is the thing the page is for. -->
        <section class="mx-auto max-w-7xl px-4 pb-20 sm:px-6 lg:px-8 lg:pb-28">
            <div class="closing relative overflow-hidden rounded-[2rem] border border-line px-6 py-12 text-center sm:px-12 sm:py-16">
                <h2 class="display mx-auto max-w-2xl text-3xl leading-tight text-ink sm:text-5xl" style="text-wrap: balance">
                    Wake up to a clean car tomorrow morning.
                </h2>

                <p class="mx-auto mt-5 max-w-xl leading-relaxed text-body">
                    Tell us where the car is kept and see the price straight away. Nothing is charged until
                    you have seen the total.
                </p>

                <div class="mt-9 flex flex-wrap justify-center gap-3">
                    <RouterLink :to="{ name: 'subscribe' }" class="btn-primary">See your price</RouterLink>
                    <RouterLink :to="{ name: 'renew' }" class="btn-quiet">Renew an existing plan</RouterLink>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.closing {
    background:
        radial-gradient(38rem 20rem at 50% -30%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 70%),
        var(--surface);
    box-shadow: var(--lift);
}
</style>
