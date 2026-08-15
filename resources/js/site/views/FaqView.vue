<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';

interface Faq { id: string; question: string; answer: string; category: string | null }

/**
 * The questions come from the database, so the office can answer a new one the
 * day it starts being asked rather than waiting for a release.
 */
const { data, isPending } = useQuery({
    queryKey: ['public-content'],
    queryFn: async () => (await api.get('/public/content')).data.data,
    staleTime: 5 * 60 * 1000,
});

const faqs = computed<Faq[]>(() => data.value?.faqs ?? []);

/**
 * Which answers are open.
 *
 * All closed to begin with: a page of open answers is a wall of text, and the
 * point of the list is to let somebody find their question first.
 */
const open = ref<Set<string>>(new Set());

function toggle(id: string) {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
}

</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Questions</h1>
        <p class="mt-1 text-body">The things people ask most often. Tap a question to see the answer.</p>

        <p v-if="isPending" class="mt-8 text-muted">Loading…</p>

        <p v-else-if="!faqs.length" class="mt-8 rounded border border-line bg-surface px-4 py-6 text-muted">
            No questions have been published yet.
        </p>

        <dl v-else class="mt-6 divide-y divide-line border-y border-line">
            <div v-for="faq in faqs" :key="faq.id">
                <dt>
                    <button
                        type="button"
                        class="flex w-full items-start gap-3 py-4 text-left transition hover:text-accent-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :aria-expanded="open.has(faq.id)"
                        :aria-controls="'answer-' + faq.id"
                        @click="toggle(faq.id)"
                    >
                        <span class="flex-1 font-semibold text-ink">{{ faq.question }}</span>
                        <svg
                            class="mt-1 h-4 w-4 shrink-0 text-muted transition-transform"
                            :class="open.has(faq.id) ? 'rotate-180' : ''"
                            viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"
                        >
                            <path d="m3 6 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </dt>
                <dd v-show="open.has(faq.id)" :id="'answer-' + faq.id" class="-mt-1 pb-4 pe-7 text-body">
                    {{ faq.answer }}
                </dd>
            </div>
        </dl>
    </div>
</template>
