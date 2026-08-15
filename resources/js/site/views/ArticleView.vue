<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { RouterLink, useRoute } from 'vue-router';
import { api, describeError } from '@/shared/api/client';

const route = useRoute();
const queryClient = useQueryClient();

const slug = computed(() => route.params.slug as string);

const { data, isPending, isError } = useQuery({
    queryKey: computed(() => ['article', slug.value]),
    queryFn: async () => (await api.get(`/public/posts/${slug.value}`)).data.data,
});

const comment = ref({ author_name: '', author_email: '', body: '' });
const submitting = ref(false);
const notice = ref<string | null>(null);
const commentError = ref<string | null>(null);

async function submitComment() {
    submitting.value = true;
    notice.value = null;
    commentError.value = null;

    try {
        const { data: result } = await api.post(`/public/posts/${slug.value}/comments`, comment.value);
        // Said plainly. Showing the comment and quietly hiding it from everyone
        // else makes people post again thinking it failed.
        notice.value = result.message;
        comment.value = { author_name: '', author_email: '', body: '' };
        await queryClient.invalidateQueries({ queryKey: ['article', slug.value] });
    } catch (e) {
        commentError.value = describeError(e).message;
    } finally {
        submitting.value = false;
    }
}

function when(iso: string): string {
    return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
}
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-10">
        <p v-if="isPending" class="text-muted">Loading…</p>

        <div v-else-if="isError" class="rounded border border-line bg-surface px-4 py-8 text-center">
            <p class="text-body">That article is not here.</p>
            <RouterLink :to="{ name: 'blog' }" class="mt-2 inline-block text-accent hover:underline">
                Back to all articles
            </RouterLink>
        </div>

        <article v-else>
            <RouterLink :to="{ name: 'blog' }" class="text-sm text-accent hover:underline">← All articles</RouterLink>

            <p v-if="data.category" class="mt-4 text-xs font-semibold uppercase tracking-widest text-accent">
                {{ data.category.name }}
            </p>

            <h1 class="mt-2 text-3xl font-bold leading-tight tracking-tight text-ink" style="text-wrap: balance">
                {{ data.title }}
            </h1>

            <p class="mt-2 text-sm text-muted">
                <template v-if="data.author">{{ data.author }} · </template>
                {{ when(data.published_at) }} · {{ data.reading_minutes }} min read
            </p>

            <!--
                Rendered as markup because the body was reduced to a short
                whitelist when it was saved. Nothing arrives here unsanitised.
            -->
            <div
                class="mt-6 text-body [&_h3]:mt-6 [&_h3]:text-lg [&_h3]:font-semibold [&_h3]:text-ink [&_li]:ms-5 [&_li]:list-disc [&_p]:mb-3 [&_strong]:text-ink [&_ul]:mb-4"
                v-html="data.body"
            />

            <div v-if="data.tags.length" class="mt-8 flex flex-wrap gap-2">
                <span
                    v-for="tag in data.tags"
                    :key="tag.slug"
                    class="rounded-full border border-line px-3 py-1 text-xs text-muted"
                >
                    {{ tag.name }}
                </span>
            </div>

            <!-- Comments -->
            <section class="mt-10 border-t border-line pt-8">
                <h2 class="text-lg font-semibold text-ink">
                    {{ data.comments.length }} comment{{ data.comments.length === 1 ? '' : 's' }}
                </h2>

                <ul v-if="data.comments.length" class="mt-4 flex flex-col gap-4">
                    <li v-for="c in data.comments" :key="c.id" class="rounded-lg border border-line bg-surface p-4">
                        <p class="text-sm font-medium text-ink">{{ c.author_name }}</p>
                        <p class="text-xs text-faint">{{ when(c.created_at) }}</p>
                        <p class="mt-2 whitespace-pre-line text-body">{{ c.body }}</p>
                    </li>
                </ul>

                <form v-if="data.comments_open" class="mt-6 flex flex-col gap-3" @submit.prevent="submitComment">
                    <h3 class="font-semibold text-ink">Leave a comment</h3>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <input
                            v-model.trim="comment.author_name"
                            type="text" required placeholder="Your name"
                            class="rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <input
                            v-model.trim="comment.author_email"
                            type="email" placeholder="Email (not published)"
                            class="rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </div>

                    <textarea
                        v-model.trim="comment.body"
                        rows="4" required placeholder="What would you like to say?"
                        class="rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />

                    <p v-if="notice" class="rounded bg-ok-soft px-3 py-2 text-sm text-ok">{{ notice }}</p>
                    <p v-if="commentError" class="rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ commentError }}</p>

                    <button
                        type="submit"
                        :disabled="submitting"
                        class="self-start rounded bg-accent px-5 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    >
                        {{ submitting ? 'Sending…' : 'Post comment' }}
                    </button>

                    <p class="text-xs text-faint">Comments are checked before they appear.</p>
                </form>

                <p v-else class="mt-6 text-sm text-muted">Comments are closed on this article.</p>
            </section>
        </article>
    </div>
</template>
