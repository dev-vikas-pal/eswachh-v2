<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';

/**
 * Privacy, terms and refunds.
 *
 * One view for all three: they are the same shape, and three near identical
 * files would drift. The text is written in the office rather than fixed in
 * code, which is what v1 got wrong - its policies were templates nobody could
 * edit without a deployment.
 *
 * The body is rendered as markup, which is safe here for one specific reason:
 * it was cleaned on the way into the database, not on the way out. The
 * sanitiser keeps a short whitelist of tags and strips every attribute, so
 * there is no onclick, no style and no href to carry anything. v1 stored
 * whatever its editor produced and rendered it raw, which handed anyone who
 * could edit a page the ability to run script on every visitor.
 *
 * If that write-time cleaning is ever removed, this v-html becomes a hole.
 */
const route = useRoute();

const page = computed(() => String(route.params.page ?? 'privacy'));

const { data, isLoading, isError } = useQuery({
    queryKey: computed(() => ['policy', page.value]),
    queryFn: async () => (await api.get(`/public/policy/${page.value}`)).data.data,
    retry: false,
});

/** When it was last changed, so a reader can see the version they are on. */
const updated = computed(() =>
    data.value?.updated_at
        ? new Date(data.value.updated_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'long', year: 'numeric' })
        : null,
);
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-10">
        <p v-if="isLoading" class="text-muted">Loading…</p>

        <div v-else-if="isError" class="rounded-lg border border-line bg-surface p-6">
            <h1 class="text-xl font-semibold text-ink">Not published yet</h1>
            <p class="mt-2 text-body">
                This page has not been written yet. Please call the office if you need it.
            </p>
        </div>

        <article v-else>
            <h1 class="text-2xl font-bold tracking-tight text-ink">{{ data.title }}</h1>
            <p class="mt-1 text-sm text-muted">
                {{ data.business_name }}<span v-if="updated"> · last updated {{ updated }}</span>
            </p>

            <!-- eslint-disable-next-line vue/no-v-html -- cleaned on write, see above -->
            <div class="policy mt-6" v-html="data.body"></div>
        </article>
    </div>
</template>

<style scoped>
/*
 * The sanitiser's whole whitelist, styled here rather than with utility classes
 * - the markup comes from the database, so there is nothing to put a class on.
 */
.policy :deep(h3) {
    margin-top: 1.75rem;
    margin-bottom: 0.5rem;
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--ink);
}

.policy :deep(p) {
    margin-bottom: 0.85rem;
    line-height: 1.7;
    color: var(--body);
}

.policy :deep(ul),
.policy :deep(ol) {
    margin-bottom: 0.85rem;
    padding-inline-start: 1.25rem;
    list-style: disc;
    color: var(--body);
}

.policy :deep(ol) {
    list-style: decimal;
}

.policy :deep(li) {
    margin-bottom: 0.35rem;
    line-height: 1.7;
}

.policy :deep(strong) {
    font-weight: 600;
    color: var(--ink);
}
</style>
