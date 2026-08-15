import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * Moving through a set of slides on a timer.
 *
 * Kept out of the component because the awkward parts are all behaviour rather
 * than markup: stopping when the tab is hidden, stopping when somebody is
 * reading, and not moving at all for a person who has asked their system for
 * less motion.
 *
 * @param count   How many slides there are, reactive so it can arrive late.
 * @param everyMs How long each slide holds.
 */
export function useCarousel(count: Ref<number>, everyMs = 6000) {
    const index = ref(0);
    const paused = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;

    /**
     * Somebody who has asked for reduced motion gets a still first slide and
     * the arrows. An automatically moving hero is exactly what that setting is
     * asking us not to do.
     */
    const reducedMotion = typeof window !== 'undefined'
        && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    /** One slide is not a slideshow: no timer, no dots, no arrows. */
    const isSlideshow = computed(() => count.value > 1 && !reducedMotion);

    function go(to: number) {
        if (count.value === 0) return;

        // Wraps both ways, so the arrows never dead-end.
        index.value = (to + count.value) % count.value;
    }

    /** Used by the timer. The exported next/previous also restart the clock. */
    const next = () => go(index.value + 1);

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start() {
        stop();

        if (!isSlideshow.value) return;

        timer = setInterval(() => {
            // Paused while somebody hovers or focuses inside, and while the tab
            // is in the background - otherwise a page left open all afternoon
            // burns through hundreds of transitions nobody sees.
            if (paused.value || document.hidden) return;

            next();
        }, everyMs);
    }

    function onVisibility() {
        if (document.hidden) stop();
        else start();
    }

    onMounted(() => {
        start();
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        stop();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    /**
     * Restart after a manual move, so a slide somebody just chose gets its full
     * turn rather than being whisked away half a second later.
     */
    function choose(to: number) {
        go(to);
        start();
    }

    return {
        index,
        paused,
        isSlideshow,
        reducedMotion,
        next: () => choose(index.value + 1),
        previous: () => choose(index.value - 1),
        choose,
        start,
        stop,
    };
}
