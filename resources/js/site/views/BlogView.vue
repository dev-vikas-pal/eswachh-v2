<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { api } from '@/shared/api/client';

const category = ref('');
const search = ref('');
const page = ref(1);

watch([category, search], () => { page.value = 1; });

const { data, isPending } = useQuery({
    queryKey: computed(() => ['blog', category.value, search.value, page.value]),
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get('/public/posts', {
        params: { category: category.value || undefined, search: search.value || undefined, page: page.value },
    })).data,
});

const posts = computed(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

function when(iso: string): string {
    return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
}
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-10">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Car care advice</h1>
        <p class="mt-1 max-w-prose text-body">Looking after a car between cleans, and what we have learned doing it daily.</p>

        <div class="mt-6 flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-full px-3 py-1 text-sm transition"
                :class="category === '' ? 'bg-accent text-on-accent' : 'border border-line-strong text-body hover:bg-sunk'"
                @click="category = ''"
            >
                Everything
            </button>
            <button
                v-for="c in data?.categories ?? []"
                :key="c.id"
                type="button"
                class="rounded-full px-3 py-1 text-sm transition"
                :class="category === c.slug ? 'bg-accent text-on-accent' : 'border border-line-strong text-body hover:bg-sunk'"
                @click="category = c.slug"
            >
                {{ c.name }}
            </button>

            <input
                v-model.trim="search"
                type="search"
                placeholder="Search"
                class="ms-auto rounded border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            />
        </div>

        <p v-if="isPending" class="mt-10 text-muted">Loading…</p>

        <p v-else-if="!posts.length" class="mt-10 rounded border border-line bg-surface px-4 py-8 text-center text-muted">
            Nothing published yet.
        </p>

        <div v-else class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="post in posts" :key="post.id" class="flex flex-col rounded-lg border border-line bg-surface p-5">
                <p v-if="post.category" class="text-xs font-semibold uppercase tracking-wide text-accent">{{ post.category }}</p>
                <h2 class="mt-1 text-lg font-semibold leading-snug text-ink">
                    <RouterLink :to="{ name: 'article', params: { slug: post.slug } }" class="hover:text-accent-ink">
                        {{ post.title }}
                    </RouterLink>
                </h2>
                <p class="mt-2 flex-1 text-sm text-body">{{ post.excerpt }}</p>
                <p class="mt-3 text-xs text-faint">
                    {{ when(post.published_at) }} · {{ post.reading_minutes }} min read
                </p>
            </article>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-6 flex items-center gap-3">
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page <= 1" @click="page--">Previous</button>
            <span class="text-sm tabular-nums text-body">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page >= meta.last_page" @click="page++">Next</button>
        </div>
    </div>
</template>
