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
            Wider than the rest of the page and with smaller gutters, so the
            hero reads as a full width band rather than a card floating in the
            middle of the screen.
        -->
        <div class="mx-auto grid max-w-[90rem] gap-6 px-4 py-10 sm:px-8 md:grid-cols-2 md:items-center md:gap-12 md:py-16 lg:px-12">
            <!--
                Keyed on the banner so Vue replaces the whole block and the
                transition runs. Without the key the text would swap in place
                with no crossfade at all.
            -->
            <Transition name="slide" mode="out-in">
                <div :key="current?.id ?? 'default'">
                    <p v-if="current?.eyebrow" class="text-sm font-semibold uppercase tracking-widest text-accent">
                        {{ current.eyebrow }}
                    </p>

                    <h1
                        class="mt-3 text-3xl font-bold leading-tight tracking-tight text-ink sm:text-4xl md:text-5xl"
                        style="text-wrap: balance"
                    >
                        {{ current?.headline ?? 'Doorstep car cleaning, every day.' }}
                    </h1>

                    <p v-if="current?.subheadline" class="mt-4 max-w-prose text-base text-body sm:text-lg">
                        {{ current.subheadline }}
                    </p>

                    <div class="mt-7 flex flex-wrap items-center gap-3">
                        <RouterLink
                            :to="{ name: current?.cta?.route || 'subscribe' }"
                            class="rounded bg-accent px-6 py-3 text-sm font-semibold text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent"
                        >
                            {{ current?.cta?.label ?? 'See your price' }}
                        </RouterLink>

                        <RouterLink
                            v-if="current?.secondary"
                            :to="{ name: current.secondary.route || 'packages' }"
                            class="rounded border border-line-strong px-6 py-3 text-sm font-semibold text-body transition hover:bg-sunk"
                        >
                            {{ current.secondary.label }}
                        </RouterLink>
                    </div>

                    <p v-if="priceFrom !== null && priceFrom !== undefined" class="mt-4 text-sm text-muted">
                        Plans from <strong class="tabular-nums text-ink">&#8377;{{ priceFrom }}</strong> a month.
                    </p>
                </div>
            </Transition>

            <!--
                A fixed aspect ratio rather than a fixed height: banners are
                uploaded at whatever size somebody had, and letting them set the
                height makes the page jump as the slides change.
            -->
            <!--
                Second on a narrow screen, so the words come first. A visitor
                who has just landed should read what this is before scrolling
                past a picture to find out.
            -->
            <div v-if="banners.some((b) => b.image)">
                <Transition name="slide" mode="out-in">
                    <div
                        :key="current?.id ?? 'image'"
                        class="aspect-[16/10] overflow-hidden rounded-lg border border-line bg-sunk sm:aspect-[16/9]"
                    >
                        <!--
                            Contained rather than cropped. The office uploads
                            whatever it has - a photograph one week, a
                            transparent illustration the next - and cropping
                            cuts the head off the second kind.
                        -->
                        <img
                            v-if="current?.image"
                            :src="current.image"
                            :alt="current.headline"
                            class="h-full w-full object-contain"
                            :loading="carousel.index.value === 0 ? 'eager' : 'lazy'"
                        />
                    </div>
                </Transition>
            </div>

            <slot v-else name="aside" />
        </div>

        <!-- Controls only when there is something to control. -->
        <div v-if="carousel.isSlideshow.value" class="pointer-events-none absolute inset-x-0 bottom-4 mx-auto flex max-w-[90rem] items-center justify-between px-4 sm:px-8 lg:px-12">
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
