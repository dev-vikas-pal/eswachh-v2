<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import RichTextEditor from '@/admin/components/RichTextEditor.vue';

interface PostRow {
    id: string;
    title: string;
    slug: string;
    excerpt: string | null;
    state: 'draft' | 'scheduled' | 'published';
    published_at: string | null;
    category: { id: string; name: string } | null;
    author: string | null;
    reading_minutes: number;
    comments_count: number | null;
    pending_comments_count: number | null;
    body?: string;
    tag_ids?: string[];
    comments_open?: boolean;
}

const queryClient = useQueryClient();

const tab = ref<'posts' | 'comments'>('posts');
const state = ref('');
const search = ref('');
const page = ref(1);

const editingId = ref<string | null>(null);
const creating = ref(false);
interface PostForm {
    title: string;
    excerpt: string;
    body: string;
    post_category_id: string;
    comments_open: boolean;
}

const form = ref<PostForm>({ title: '', excerpt: '', body: '', post_category_id: '', comments_open: true });
const formError = ref<string | null>(null);
const saving = ref(false);

watch([state, search], () => { page.value = 1; });

const { data, isPending } = useQuery({
    queryKey: computed(() => ['posts', state.value, search.value, page.value]),
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get('/posts', {
        params: { state: state.value || undefined, search: search.value || undefined, page: page.value },
    })).data,
});

const posts = computed<PostRow[]>(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

const { data: commentData } = useQuery({
    queryKey: computed(() => ['post-comments', tab.value]),
    enabled: computed(() => tab.value === 'comments'),
    queryFn: async () => (await api.get('/posts/comments', { params: { status: 'pending' } })).data,
});

const { data: categories } = useQuery({
    queryKey: ['masters', 'post-categories', 'options'],
    queryFn: async () => (await api.get('/masters/post-categories')).data.data,
    staleTime: 5 * 60 * 1000,
});

/** The full article, loaded only when one is opened for editing. */
const { data: editing } = useQuery({
    queryKey: computed(() => ['post', editingId.value]),
    enabled: computed(() => editingId.value !== null),
    queryFn: async () => (await api.get(`/posts/${editingId.value}`)).data.data,
});

/*
 * `immediate` guards the same trap that opened the plan editor blank: a query
 * whose key is already cached hands its data over before the watcher is
 * registered, so nothing ever changes and nothing ever fills the form. It costs
 * one no-op call here and removes the possibility.
 */
watch(editing, (post) => {
    if (!post) return;
    form.value = {
        title: post.title,
        excerpt: post.excerpt ?? '',
        body: post.body ?? '',
        post_category_id: post.category?.id ?? '',
        comments_open: post.comments_open ?? true,
    };
}, { immediate: true });

function startNew() {
    formError.value = null;
    editingId.value = null;
    creating.value = true;
    form.value = { title: '', excerpt: '', body: '', post_category_id: '', comments_open: true };
}

function startEdit(row: PostRow) {
    formError.value = null;
    creating.value = false;
    editingId.value = row.id;
}

function close() {
    editingId.value = null;
    creating.value = false;
}

async function save() {
    saving.value = true;
    formError.value = null;

    try {
        if (editingId.value) {
            await api.patch(`/posts/${editingId.value}`, form.value);
        } else {
            await api.post('/posts', form.value);
        }
        close();
        await queryClient.invalidateQueries({ queryKey: ['posts'] });
    } catch (e) {
        formError.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}

/**
 * Publishing is its own action, not a field on save - an article should not go
 * live as a side effect of fixing a typo.
 */
async function setPublished(row: PostRow, publish: boolean) {
    await api.post(`/posts/${row.id}/publish`, { publish });
    await queryClient.invalidateQueries({ queryKey: ['posts'] });
}

async function remove(row: PostRow) {
    if (!confirm(`Remove "${row.title}"? It comes off the site straight away.`)) return;
    await api.delete(`/posts/${row.id}`);
    await queryClient.invalidateQueries({ queryKey: ['posts'] });
}

async function moderate(id: string, status: string) {
    await api.patch(`/posts/comments/${id}`, { status });
    await queryClient.invalidateQueries({ queryKey: ['post-comments'] });
    await queryClient.invalidateQueries({ queryKey: ['posts'] });
}

const stateClass: Record<string, string> = {
    published: 'bg-ok-soft text-ok',
    scheduled: 'bg-warn-soft text-warn',
    draft: 'bg-sunk text-muted',
};

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Blog</h1>

            <nav class="flex gap-1">
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm font-medium transition"
                    :class="tab === 'posts' ? 'bg-accent-soft text-accent-ink' : 'text-body hover:bg-sunk'"
                    @click="tab = 'posts'"
                >
                    Articles
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm font-medium transition"
                    :class="tab === 'comments' ? 'bg-accent-soft text-accent-ink' : 'text-body hover:bg-sunk'"
                    @click="tab = 'comments'"
                >
                    Comments
                    <span
                        v-if="meta?.pending_comments"
                        class="ms-1 rounded-full bg-warn px-1.5 text-xs font-bold text-on-accent"
                    >
                        {{ meta.pending_comments }}
                    </span>
                </button>
            </nav>

            <button
                v-if="tab === 'posts'"
                type="button"
                class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110"
                @click="startNew"
            >
                Write an article
            </button>
        </div>

        <!-- Articles -->
        <template v-if="tab === 'posts'">
            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
                <label>
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Show</span>
                    <select v-model="state" class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="">Everything</option>
                        <option value="published">Published</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="draft">Drafts</option>
                    </select>
                </label>
                <label>
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                    <input v-model.trim="search" type="search" class="rounded border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                </label>
            </div>

            <div class="overflow-x-auto rounded-lg border border-line bg-surface">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                            <th class="px-3 py-2 font-medium">Title</th>
                            <th class="px-3 py-2 font-medium">Category</th>
                            <th class="px-3 py-2 font-medium">Published</th>
                            <th class="px-3 py-2 text-right font-medium">Comments</th>
                            <th class="px-3 py-2 font-medium">State</th>
                            <th class="px-3 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="isPending"><td colspan="6" class="px-3 py-6 text-center text-muted">Loading…</td></tr>
                        <tr v-else-if="!posts.length"><td colspan="6" class="px-3 py-6 text-center text-muted">Nothing written yet.</td></tr>
                        <tr v-for="post in posts" :key="post.id" class="border-b border-line last:border-0 hover:bg-sunk">
                            <td class="px-3 py-2 font-medium text-ink">
                                {{ post.title }}
                                <div class="text-xs text-faint">{{ post.reading_minutes }} min read</div>
                            </td>
                            <td class="px-3 py-2 text-body">{{ post.category?.name ?? '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap tabular-nums text-body">{{ when(post.published_at) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-body">
                                {{ post.comments_count ?? 0 }}
                                <span v-if="post.pending_comments_count" class="text-warn">
                                    ({{ post.pending_comments_count }} waiting)
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="rounded px-2 py-0.5 text-xs font-medium" :class="stateClass[post.state]">
                                    {{ post.state }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <button type="button" class="rounded px-2 py-1 text-xs font-medium text-accent-ink hover:bg-accent-soft" @click="startEdit(post)">Edit</button>
                                <button
                                    v-if="post.state !== 'published'"
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-ok hover:bg-ok-soft"
                                    @click="setPublished(post, true)"
                                >
                                    Publish
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-warn hover:bg-warn-soft"
                                    @click="setPublished(post, false)"
                                >
                                    Unpublish
                                </button>
                                <button type="button" class="rounded px-2 py-1 text-xs font-medium text-crit hover:bg-crit-soft" @click="remove(post)">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center gap-3">
                <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page <= 1" @click="page--">Previous</button>
                <span class="text-sm tabular-nums text-body">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page >= meta.last_page" @click="page++">Next</button>
            </div>
        </template>

        <!-- Comments waiting -->
        <template v-else>
            <p class="mb-3 text-sm text-muted">
                Nothing a reader writes appears on the site until it is approved here.
            </p>

            <p v-if="!(commentData?.data ?? []).length" class="rounded border border-line bg-surface px-4 py-8 text-center text-muted">
                Nothing waiting.
            </p>

            <ul v-else class="flex flex-col gap-3">
                <li v-for="comment in commentData.data" :key="comment.id" class="rounded-lg border border-line bg-surface p-4">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="font-medium text-ink">{{ comment.author_name }}</span>
                        <span class="text-xs text-faint">on {{ comment.post?.title }}</span>
                    </div>
                    <p class="mt-2 whitespace-pre-line text-body">{{ comment.body }}</p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" class="rounded bg-ok-soft px-3 py-1 text-xs font-medium text-ok hover:brightness-110" @click="moderate(comment.id, 'approved')">
                            Put it on the site
                        </button>
                        <button type="button" class="rounded bg-crit-soft px-3 py-1 text-xs font-medium text-crit hover:brightness-110" @click="moderate(comment.id, 'spam')">
                            Reject
                        </button>
                    </div>
                </li>
            </ul>
        </template>

        <!-- Write / edit -->
        <div v-if="creating || editingId" class="fixed inset-0 z-40 flex items-start justify-center bg-black/30 p-4 pt-10 overflow-y-auto">
            <div class="w-full max-w-2xl rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
                <h2 class="mb-3 text-lg font-semibold text-ink">{{ editingId ? 'Edit article' : 'Write an article' }}</h2>

                <form class="flex flex-col gap-3" @submit.prevent="save">
                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Title</span>
                        <input v-model.trim="form.title" type="text" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>

                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Category</span>
                        <select v-model="form.post_category_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                            <option value="">None</option>
                            <option v-for="c in categories ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Summary</span>
                        <textarea v-model="form.excerpt" rows="2" placeholder="Left blank, this is taken from the opening lines." class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>

                    <div>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Article</span>
                        <RichTextEditor v-model="form.body" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-body">
                        <input v-model="form.comments_open" type="checkbox" class="rounded border-line-strong" />
                        Let readers comment
                    </label>

                    <p v-if="formError" class="rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ formError }}</p>

                    <div class="mt-1 flex gap-2">
                        <button type="submit" :disabled="saving" class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60">
                            {{ saving ? 'Saving…' : 'Save as draft' }}
                        </button>
                        <button type="button" class="rounded border border-line-strong px-4 py-2 text-sm text-body transition hover:bg-sunk" @click="close">
                            Cancel
                        </button>
                        <span class="self-center text-xs text-faint">Publishing is a separate step.</span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
