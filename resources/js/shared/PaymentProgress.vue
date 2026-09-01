<script setup lang="ts">
import { computed } from 'vue';
import { blocksTheScreen, paymentProgress } from '@/shared/paymentProgress';

/**
 * The sheet that holds the screen while a payment is going through.
 *
 * Mounted once per bundle, beside the router view, so every payment screen gets
 * it without asking. What it is for is narrow and worth being blunt about:
 * between the gateway taking the money and our server writing it down there is
 * a second or two where a customer who clicks something, or refreshes, ends up
 * charged and looking at a page that never acknowledged it. The reconciler
 * catches that overnight - but a customer who has just paid you and been shown
 * nothing does not wait until the morning, they phone the office.
 *
 * So: cover the page, say what is happening, and say plainly not to touch
 * anything. It is deliberately not dismissible. A sheet somebody can close is
 * a sheet that has not stopped them doing the thing it was there to prevent.
 */
const showing = computed(() => blocksTheScreen(paymentProgress.phase));

const words = computed(() =>
    paymentProgress.phase === 'confirming'
        ? {
            title: 'Confirming your payment',
            /*
             * Named separately from the opening step because they are not the
             * same risk. Money has changed hands by this point, and this is the
             * sentence that has to stop somebody reaching for the reload button.
             */
            body: 'Please wait. Do not click anywhere or refresh the page. '
                + 'This finishes on its own, and you will see a confirmation when it is done.',
        }
        : {
            title: 'Opening the payment',
            body: 'Please wait. Do not click anywhere or refresh the page. '
                + 'The payment window will open on its own.',
        },
);
</script>

<template>
    <!--
        A fade in only. Fading out would leave the sheet hanging over the
        confirmation for a moment at exactly the wrong time.
    -->
    <Transition name="veil">
        <div
            v-if="showing"
            class="fixed inset-0 z-[100] grid place-items-center bg-black/60 p-4 backdrop-blur-sm"
            role="alertdialog"
            aria-live="assertive"
            aria-modal="true"
            :aria-label="words.title"
        >
            <div class="w-full max-w-sm rounded-xl border border-line-strong bg-surface p-7 text-center shadow-2xl">
                <!--
                    A ring rather than a bar: this has no measurable progress,
                    and a bar that creeps to 90% and stops is a worse lie than
                    a spinner that admits it does not know.
                -->
                <svg class="mx-auto h-11 w-11 animate-spin text-accent" viewBox="0 0 44 44" fill="none" aria-hidden="true">
                    <circle cx="22" cy="22" r="18" stroke="currentColor" stroke-width="4" class="opacity-20" />
                    <path
                        d="M22 4a18 18 0 0 1 18 18"
                        stroke="currentColor"
                        stroke-width="4"
                        stroke-linecap="round"
                    />
                </svg>

                <h2 class="mt-5 text-lg font-semibold text-ink">{{ words.title }}</h2>

                <p class="mt-2 text-sm leading-relaxed text-body">{{ words.body }}</p>

                <p class="mt-5 border-t border-line pt-4 text-xs text-muted">
                    If money leaves your account and something goes wrong here, it is applied to your
                    plan automatically. Nothing is ever charged twice.
                </p>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.veil-enter-active {
    transition: opacity 0.18s ease;
}

.veil-enter-from {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .veil-enter-active {
        transition: none;
    }
}
</style>
