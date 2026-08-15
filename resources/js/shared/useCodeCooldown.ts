import { onBeforeUnmount, readonly, ref } from 'vue';

/**
 * The wait between asking for a code and being allowed to ask again.
 *
 * A resend button with no cooldown is worse than no resend button: people press
 * it the moment the message is slow, each press spends the previous code, and
 * the one that finally arrives is two codes out of date. So the button is
 * disabled for a spell and says how long, rather than silently doing nothing or
 * quietly invalidating what they are about to type.
 *
 * The server limits this as well - three requests per number per ten minutes -
 * and that is the limit that matters. This is only so the button behaves.
 */
export function useCodeCooldown(seconds = 30) {
    const remaining = ref(0);
    let timer: ReturnType<typeof setInterval> | null = null;

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start(from = seconds) {
        stop();
        remaining.value = from;

        timer = setInterval(() => {
            remaining.value -= 1;

            if (remaining.value <= 0) stop();
        }, 1000);
    }

    // A component torn down mid-countdown must not leave the interval running.
    onBeforeUnmount(stop);

    return { remaining: readonly(remaining), start, stop };
}
