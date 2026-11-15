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
 * The words sit on the page's own paper, on the left; the picture is a framed
 * panel on the right. It used to be the other way about - a full-bleed image
 * with the words washed over it - which only works when every image is dark,
 * and made the top of a light site a black band. A frame takes whatever the
 * office uploads, photograph or illustration, without the headline having to
 * fight it for contrast.
 *
 * Responsive by stacking rather than shrinking - on a phone the words go above
 * the picture and the picture keeps a sensible aspect ratio.
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
 * Water beading on freshly cleaned paintwork, for a banner with no picture.
 *
 * Drawn here rather than shipped as an image: it is the panel's default, so it
 * should cost nothing to load and be right at every size. Seeded, so the same
 * beads are in the same places on every visit - a picture that rearranges
 * itself between page loads reads as a glitch.
 *
 * Weighted towards the upper right, where the light is, and away from the
 * lower left, where the day's message sits on top of the panel.
 */
const beads = (() => {
    let seed = 20261115;
    const rand = () => {
        seed = (seed * 1664525 + 1013904223) % 4294967296;
        return seed / 4294967296;
    };

    const out: Array<{ left: string; top: string; size: string; opacity: number }> = [];

    while (out.length < 64) {
        const x = Math.pow(rand(), 0.6) * 100;
        const y = rand() * 100;

        if (x < 46 && y > 52) continue;

        const r = rand();
        const size = r > 0.94 ? 20 + rand() * 12 : r > 0.7 ? 11 + rand() * 7 : 4 + rand() * 5;

        out.push({
            left: x.toFixed(2) + '%',
            top: y.toFixed(2) + '%',
            size: size.toFixed(1) + 'px',
            opacity: 0.55 + (x / 100) * 0.45,
        });
    }

    return out;
})();

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
        class="hero relative overflow-hidden"
        aria-roledescription="carousel"
        aria-label="Offers"
        @mouseenter="carousel.paused.value = true"
        @mouseleave="carousel.paused.value = false"
        @focusin="carousel.paused.value = true"
        @focusout="carousel.paused.value = false"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
    >
        <div class="relative mx-auto grid max-w-7xl gap-12 px-4 pb-16 pt-10 sm:px-6 sm:pt-14 lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:gap-16 lg:px-8 lg:pb-24 lg:pt-20">
            <div>
                <!--
                    Keyed on the banner so Vue replaces the whole block and the
                    transition runs. Without the key the text would swap in
                    place with no crossfade at all.

                    Both slides occupy the same grid cell and neither waits for
                    the other. With mode="out-in" the outgoing headline is
                    removed first and the incoming one begins only once it has
                    gone - so for a third of a second every six seconds the hero
                    had no words in it at all.
                -->
                <div class="relative grid">
                    <Transition name="slide">
                        <div :key="current?.id ?? 'default'" class="[grid-area:1/1]">
                            <p v-if="current?.eyebrow" class="eyebrow">{{ current.eyebrow }}</p>

                            <h1 class="mt-5 text-[2.6rem] leading-[1.04] text-ink sm:text-6xl lg:text-[4.25rem]" style="text-wrap: balance">
                                {{ current?.headline ?? 'Doorstep car cleaning, every day.' }}
                            </h1>

                            <p v-if="current?.subheadline" class="mt-6 max-w-xl text-lg leading-relaxed text-body">
                                {{ current.subheadline }}
                            </p>

                            <div class="mt-9 flex flex-wrap items-center gap-3">
                                <!-- One obvious next step, weighted so it is obvious. -->
                                <RouterLink :to="{ name: current?.cta?.route || 'subscribe' }" class="btn-primary">
                                    {{ current?.cta?.label ?? 'See your price' }}
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M4 10h12m-5-5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </RouterLink>

                                <RouterLink
                                    v-if="current?.secondary"
                                    :to="{ name: current.secondary.route || 'packages' }"
                                    class="btn-quiet"
                                >
                                    {{ current.secondary.label }}
                                </RouterLink>
                            </div>
                        </div>
                    </Transition>
                </div>

                <!--
                    The price, and the two things people ask before giving a
                    phone number. Outside the transition: they are true of
                    every slide, so they should not leave and come back.
                -->
                <ul class="mt-9 flex flex-wrap items-center gap-x-6 gap-y-2.5 text-sm text-body">
                    <li v-if="priceFrom !== null && priceFrom !== undefined" class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-accent" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path d="m4 10.5 4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Plans from <strong class="tabular-nums text-ink">&#8377;{{ priceFrom }}</strong> a month
                    </li>
                    <li class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-accent" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path d="m4 10.5 4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        No contract
                    </li>
                    <li class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-accent" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path d="m4 10.5 4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Price shown before you pay
                    </li>
                </ul>

                <!-- Controls only when there is something to control. -->
                <div v-if="carousel.isSlideshow.value" class="mt-10 flex items-center gap-4">
                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-full border border-line-strong bg-surface text-ink transition hover:border-ink"
                        aria-label="Previous offer"
                        @click="carousel.previous()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="m12 4-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <div class="flex items-center gap-2">
                        <button
                            v-for="(banner, i) in banners"
                            :key="banner.id"
                            type="button"
                            class="h-2 rounded-full transition-all"
                            :class="i === carousel.index.value ? 'w-7 bg-accent' : 'w-2 bg-line-strong hover:bg-muted'"
                            :aria-label="'Show offer ' + (i + 1)"
                            :aria-current="i === carousel.index.value"
                            @click="carousel.choose(i)"
                        />
                    </div>

                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-full border border-line-strong bg-surface text-ink transition hover:border-ink"
                        aria-label="Next offer"
                        @click="carousel.next()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="m8 4 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </div>

            <!--
                The picture. A banner's own image when it has one, and the
                drawn panel when it does not - so a banner saved with nothing
                but a headline still gets a finished hero.

                The slot lays things over the panel; the home page uses it for
                an example of the daily message.
            -->
            <div class="relative mx-auto w-full max-w-xl lg:max-w-none">
                <div class="panel relative aspect-[5/4] overflow-hidden rounded-[2rem] sm:aspect-[4/3] lg:aspect-[5/5.2]">
                    <Transition name="fade">
                        <img
                            v-if="current?.image"
                            :key="current.id"
                            :src="current.image"
                            alt=""
                            aria-hidden="true"
                            class="absolute inset-0 h-full w-full object-cover"
                            :loading="carousel.index.value === 0 ? 'eager' : 'lazy'"
                            :fetchpriority="carousel.index.value === 0 ? 'high' : 'auto'"
                        />

                        <div v-else key="paint" class="paint absolute inset-0" aria-hidden="true">
                            <div class="sun"></div>
                            <div class="horizon"></div>
                            <div class="crease"></div>
                            <span
                                v-for="(bead, i) in beads"
                                :key="i"
                                class="bead"
                                :style="{ left: bead.left, top: bead.top, width: bead.size, height: bead.size, opacity: bead.opacity }"
                            ></span>
                        </div>
                    </Transition>
                </div>

                <slot name="over" />
            </div>
        </div>
    </section>
</template>

<style scoped>
/*
    A wash of the brand colour behind the hero, off the top right where the
    panel's own light comes from, so the two read as lit by the same morning.
*/
.hero {
    background:
        radial-gradient(60rem 34rem at 88% -8%, color-mix(in srgb, var(--accent) 13%, transparent), transparent 62%),
        radial-gradient(40rem 26rem at -6% 108%, color-mix(in srgb, var(--accent) 6%, transparent), transparent 60%);
}

.panel {
    background: var(--sunk);
    box-shadow:
        0 0 0 1px rgb(74 48 20 / 0.06),
        0 40px 70px -34px rgb(74 48 20 / 0.42);
}

/*
    The paintwork: a pale pearl body colour lit from the upper right.

    The band of stops close together a fifth of the way down is the horizon
    reflected in the panel. It is that edge, more than any amount of gloss,
    that makes a flat gradient read as the side of a car.
*/
.paint {
    background: linear-gradient(
        168deg,
        #FFF4E6 0%,
        #FFE2C0 14%,
        #FFC98F 20%,
        #FFF0DD 26%,
        #FFFFFF 38%,
        #F8EFE3 62%,
        #EFE2D0 100%
    );
}

.sun {
    position: absolute;
    right: -18%;
    top: -34%;
    width: 90%;
    height: 90%;
    background: radial-gradient(closest-side, rgb(255 190 110 / 0.75), rgb(255 158 80 / 0.28) 48%, transparent 76%);
    filter: blur(18px);
}

.horizon {
    position: absolute;
    inset: auto -10% -22% 12%;
    height: 62%;
    background: radial-gradient(60% 80% at 55% 40%, rgb(158 196 222 / 0.34), transparent 72%);
    filter: blur(26px);
}

/* The body line along a car's flank, catching light along its length. */
.crease {
    position: absolute;
    left: -6%;
    right: -6%;
    top: 46%;
    height: 5px;
    background: linear-gradient(to right, transparent, rgb(255 255 255 / 0.95) 30%, #FFFFFF 60%, transparent);
    box-shadow: 0 10px 22px 6px rgb(190 140 90 / 0.20);
    filter: blur(2px);
    transform: rotate(-5deg);
}

/*
    One bead: a specular pin where the light is, the refracted body of the
    drop, and a lit rim where the light comes back out. On pale paint the body
    has to be a shade darker than the panel or the drop disappears.
*/
.bead {
    position: absolute;
    border-radius: 50%;
    transform: translate(-50%, -50%);
    background:
        radial-gradient(circle at 32% 26%, rgb(255 255 255 / 0.98) 0%, rgb(255 255 255 / 0.5) 14%, transparent 32%),
        radial-gradient(circle at 68% 76%, rgb(255 236 214 / 0.9) 0%, transparent 44%),
        radial-gradient(circle at 50% 50%, rgb(214 168 122 / 0.10) 0%, rgb(190 138 92 / 0.22) 64%, rgb(140 92 52 / 0.36) 100%);
    box-shadow:
        inset 0 -1px 2px rgb(255 255 255 / 0.8),
        0 2px 4px rgb(120 78 40 / 0.26);
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
