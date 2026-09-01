<script setup lang="ts">
import { computed, ref, toRef } from 'vue';
import { RouterLink } from 'vue-router';
import { useCarousel } from '@/shared/useCarousel';

/**
 * The home page hero, cycling through whatever is live in the Banner master.
 *
 * Everything on a slide is optional except the headline, because the master
 * lets it be: a banner with no image, no subheadline and no second button is a
 * legitimate thing for the office to save, and the layout has to hold together
 * when they do.
 *
 * Responsive by stacking rather than shrinking - on a phone the words go above
 * the picture and the picture keeps a sensible aspect ratio, instead of a wide
 * hero image being squeezed into a letterbox nobody can see anything in.
 */
export interface Banner {
    id: string;
    eyebrow: string | null;
    headline: string;
    subheadline: string | null;
    cta: { label: string; route: string } | null;
    secondary: { label: string; route: string } | null;
    image: string | null;
}

const props = defineProps<{ banners: Banner[]; priceFrom?: number | null }>();

const count = computed(() => props.banners.length);
const carousel = useCarousel(toRef(count), 6000);

const current = computed<Banner | null>(() => props.banners[carousel.index.value] ?? null);

/**
 * Does any live banner carry a picture?
 *
 * Decided across all of them, not per slide: switching between a full-bleed
 * image band and a two-column layout mid-rotation makes the page jump, and the
 * three steps would appear and vanish as the slides changed.
 */
const hasImage = computed(() => props.banners.some((b) => b.image));

/** Swiping, which is how a phone expects to move between slides. */
const touchStart = ref<number | null>(null);

function onTouchStart(event: TouchEvent) {
    touchStart.value = event.changedTouches[0]?.clientX ?? null;
}

function onTouchEnd(event: TouchEvent) {
    if (touchStart.value === null) return;

    const moved = (event.changedTouches[0]?.clientX ?? 0) - touchStart.value;
    touchStart.value = null;

    // Forty pixels, so a slightly untidy vertical scroll is not read as a swipe.
    if (Math.abs(moved) < 40) return;

    if (moved < 0) carousel.next();
    else carousel.previous();
}
</script>

<template>
    <section
        class="relative border-b border-line bg-surface"
        :class="{ 'hero-dark': hasImage }"
        aria-roledescription="carousel"
        aria-label="Offers"
        @mouseenter="carousel.paused.value = true"
        @mouseleave="carousel.paused.value = false"
        @focusin="carousel.paused.value = true"
        @focusout="carousel.paused.value = false"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
    >
        <!--
            The picture spans the whole band, with the words laid over it.

            A hero image in a half column is a picture beside some text; the
            same image across the full width is the page. The rest of the site
            is centred in a reading column because prose needs one - a hero does
            not, and a band that stops short of the screen reads as a card that
            failed to load the rest of itself.
        -->
        <div v-if="hasImage" class="absolute inset-0 overflow-hidden">
            <Transition name="fade" mode="out-in">
                <img
                    v-if="current?.image"
                    :key="current.id"
                    :src="current.image"
                    alt=""
                    aria-hidden="true"
                    class="h-full w-full scale-105 object-cover"
                    :loading="carousel.index.value === 0 ? 'eager' : 'lazy'"
                    :fetchpriority="carousel.index.value === 0 ? 'high' : 'auto'"
                />
            </Transition>

            <!--
                The words have to stay readable over whatever was uploaded, and
                the office uploads a photograph one week and a pale illustration
                the next.

                Two washes rather than one. The horizontal pass carries the text
                side; the vertical pass darkens the foot of the band so the dots
                and arrows keep their contrast over a bright sky, which a single
                left-to-right gradient cannot do.

                Lighter than it was on the right. The banners that ship with the
                system are drawn dark down that side already, and stacking a
                near-opaque wash on top of a picture that has done the work
                itself only flattens it - but the wash stays heavy enough on the
                left to carry a headline over something bright somebody uploads
                next month.
            -->
            <div class="absolute inset-0 bg-gradient-to-r from-surface via-surface/75 to-surface/25 md:via-surface/45 md:to-transparent"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-surface/60 via-transparent to-transparent"></div>
        </div>

        <!--
            Taller than it was, with a minimum height so a short headline does
            not produce a thin strip that reads as a notification bar rather
            than the top of a page.
        -->
        <div class="relative grid w-full gap-6 px-4 py-16 sm:px-8 md:min-h-[30rem] md:grid-cols-2 md:items-center md:gap-12 md:py-24 lg:px-16 xl:px-24">
            <!--
                Keyed on the banner so Vue replaces the whole block and the
                transition runs. Without the key the text would swap in place
                with no crossfade at all.

                Both slides occupy the same grid cell and neither waits for the
                other. This was mode="out-in", which means exactly what it says:
                the outgoing headline is removed, and only once it has finished
                leaving does the incoming one begin - so for a third of a second
                every six seconds the hero had no words in it at all. It is easy
                to miss watching for it and impossible to miss out of the corner
                of your eye.
            -->
            <div class="relative grid">
            <Transition name="slide">
                <div :key="current?.id ?? 'default'" class="[grid-area:1/1]">
                    <p v-if="current?.eyebrow" class="text-sm font-semibold uppercase tracking-widest text-accent">
                        {{ current.eyebrow }}
                    </p>

                    <h1
                        class="mt-3 text-4xl font-bold leading-[1.08] tracking-tight text-ink sm:text-5xl md:text-6xl"
                        style="text-wrap: balance"
                    >
                        {{ current?.headline ?? 'Doorstep car cleaning, every day.' }}
                    </h1>

                    <p v-if="current?.subheadline" class="mt-5 max-w-prose text-lg leading-relaxed text-body">
                        {{ current.subheadline }}
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <!--
                            One obvious next step, weighted so it is obvious.
                            The shadow is on the primary only: two buttons that
                            both lift are two buttons that neither leads.
                        -->
                        <RouterLink
                            :to="{ name: current?.cta?.route || 'subscribe' }"
                            class="rounded-lg bg-accent px-7 py-3.5 text-sm font-semibold text-on-accent shadow-lg shadow-accent/20 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        >
                            {{ current?.cta?.label ?? 'See your price' }}
                        </RouterLink>

                        <RouterLink
                            v-if="current?.secondary"
                            :to="{ name: current.secondary.route || 'packages' }"
                            class="rounded-lg border border-line-strong bg-surface/70 px-7 py-3.5 text-sm font-semibold text-body backdrop-blur transition hover:bg-sunk"
                        >
                            {{ current.secondary.label }}
                        </RouterLink>
                    </div>

                    <!--
                        The price, and the two things people ask before giving a
                        phone number. Said here so neither costs a scroll.
                    -->
                    <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted">
                        <p v-if="priceFrom !== null && priceFrom !== undefined">
                            Plans from <strong class="tabular-nums text-ink">&#8377;{{ priceFrom }}</strong> a month
                        </p>
                        <span class="hidden h-3 w-px bg-line-strong sm:block" aria-hidden="true"></span>
                        <p>No contract</p>
                        <span class="hidden h-3 w-px bg-line-strong sm:block" aria-hidden="true"></span>
                        <p>Price shown before you pay</p>
                    </div>
                </div>
            </Transition>
            </div>

            <!--
                The picture is the background now, so this column holds the
                three steps when there is no picture and nothing when there is.
                Keeping the empty column means the words stay on their half of
                a wide screen rather than stretching across the whole band.
            -->
            <slot v-if="!hasImage" name="aside" />
            <div v-else aria-hidden="true"></div>
        </div>

        <!-- Controls only when there is something to control. -->
        <div v-if="carousel.isSlideshow.value" class="pointer-events-none absolute inset-x-0 bottom-4 flex items-center justify-between px-4 sm:px-8 lg:px-16 xl:px-24">
            <button
                type="button"
                class="pointer-events-auto grid h-9 w-9 place-items-center rounded-full border border-line-strong bg-surface/80 text-body backdrop-blur transition hover:bg-sunk focus:outline-none focus:ring-2 focus:ring-accent"
                aria-label="Previous offer"
                @click="carousel.previous()"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="m12 4-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <div class="pointer-events-auto flex items-center gap-2">
                <button
                    v-for="(banner, i) in banners"
                    :key="banner.id"
                    type="button"
                    class="h-2 rounded-full transition-all"
                    :class="i === carousel.index.value ? 'w-6 bg-accent' : 'w-2 bg-line-strong hover:bg-muted'"
                    :aria-label="'Show offer ' + (i + 1)"
                    :aria-current="i === carousel.index.value"
                    @click="carousel.choose(i)"
                />
            </div>

            <button
                type="button"
                class="pointer-events-auto grid h-9 w-9 place-items-center rounded-full border border-line-strong bg-surface/80 text-body backdrop-blur transition hover:bg-sunk focus:outline-none focus:ring-2 focus:ring-accent"
                aria-label="Next offer"
                @click="carousel.next()"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="m8 4 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        </div>
    </section>
</template>

<style scoped>
/*
    A hero carrying a picture is a dark band in both themes.

    The banner images are lit scenes - dark paintwork with the light coming
    from one side - and a page in light mode was laying a white wash over them
    until they came out as grey. Rather than ship a second set of images for
    the other theme, the band declares its own: the tokens are redefined here
    and everything inside picks them up through the classes it already uses,
    so nothing in the markup has to know which theme is on.

    This is a deliberate one-way decision. A hero that stays dark while the
    page around it turns light is a normal thing for a site to do and reads as
    intentional; a hero that changes its mind about which image it can carry
    does not.
*/
.hero-dark {
    --surface: #17110D;
    --sunk: #1F1813;
    --ink: #FFFFFF;
    --body: #E6DED8;
    --muted: #B8ADA5;
    --faint: #8B8079;
    --line: rgba(255, 255, 255, 0.14);
    --line-strong: rgba(255, 255, 255, 0.26);

    --accent: #FB923C;
    --accent-ink: #FDBA74;
    --accent-soft: rgba(251, 146, 60, 0.16);
    --on-accent: #1C1005;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.5s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
    transition: opacity 0.35s ease, transform 0.35s ease;
}

.slide-enter-from {
    opacity: 0;
    transform: translateX(1.25rem);
}

.slide-leave-to {
    opacity: 0;
    transform: translateX(-1.25rem);
}

/* The setting is honoured in the timer as well; this covers the manual moves. */
@media (prefers-reduced-motion: reduce) {
    .slide-enter-active,
    .slide-leave-active {
        transition: opacity 0.2s ease;
    }

    .slide-enter-from,
    .slide-leave-to {
        transform: none;
    }
}
</style>
