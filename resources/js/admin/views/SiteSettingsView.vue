<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import RichTextEditor from '@/admin/components/RichTextEditor.vue';

interface Field { key: string; label: string; value: string; long: boolean; rich: boolean; boolean: boolean }
interface Group { group: string; fields: Field[] }

const { data, isPending, refetch } = useQuery({
    queryKey: ['site-settings'],
    queryFn: async () => (await api.get('/site-settings')).data,
});

const groups = computed<Group[]>(() => data.value?.data ?? []);
const form = ref<Record<string, string>>({});
const saving = ref(false);
const saved = ref(false);
const error = ref<string | null>(null);

// Fill the form once the values arrive, and again after a save.
watch(groups, (next) => {
    const values: Record<string, string> = {};
    for (const group of next) {
        for (const field of group.fields) values[field.key] = field.value ?? '';
    }
    form.value = values;
}, { immediate: true });

async function save() {
    saving.value = true;
    saved.value = false;
    error.value = null;

    try {
        await api.patch('/site-settings', form.value);
        saved.value = true;

        /*
         * Some of these settings decide what the application is, not just what
         * it says: switching the blog, the team page or the cloth service off
         * removes screens, menu items and routes.
         *
         * Those are drawn from the flags that arrived with the session, so
         * without this the menu went on offering a screen the server had just
         * started refusing - and the only way to see the change was a full
         * reload, which nobody thinks to do after pressing Save.
         */
        await Promise.all([refetch(), useAuthStore().loadSession()]);
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="max-w-3xl">
        <h1 class="text-xl font-semibold tracking-tight text-ink">Business details</h1>
        <p class="mt-1 text-body">
            What appears on invoices and on the public site. These apply to the whole business,
            not one branch.
        </p>

        <p v-if="data?.note" class="mt-3 rounded border border-line bg-sunk px-3 py-2 text-sm text-muted">
            {{ data.note }}
        </p>

        <p v-if="isPending" class="mt-8 text-muted">Loading…</p>

        <form v-else class="mt-6 flex flex-col gap-6" @submit.prevent="save">
            <section v-for="group in groups" :key="group.group" class="rounded-lg border border-line bg-surface p-4">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">{{ group.group }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <!--
                        A policy page needs headings and lists to be readable, so
                        it gets the editor. Everything else is a line of text.
                        The label is a div rather than a label around the editor:
                        a contenteditable inside a label swallows the click that
                        should place the cursor.
                    -->
                    <div v-for="field in group.fields" :key="field.key" :class="field.long || field.rich ? 'sm:col-span-2' : ''">
                        <component :is="field.rich ? 'div' : 'label'">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                                {{ field.label }}
                            </span>

                            <!-- A yes or no question, as a switch. -->
                            <label v-if="field.boolean" class="flex items-center gap-2 text-sm text-body">
                                <input
                                    type="checkbox"
                                    class="accent-[var(--accent)]"
                                    :checked="form[field.key] === '1'"
                                    @change="form[field.key] = ($event.target as HTMLInputElement).checked ? '1' : '0'"
                                />
                                Yes
                            </label>

                            <RichTextEditor v-else-if="field.rich" v-model="form[field.key]" />

                            <textarea
                                v-else-if="field.long"
                                v-model="form[field.key]"
                                rows="3"
                                class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />

                            <input
                                v-else
                                v-model="form[field.key]"
                                type="text"
                                class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </component>

                        <p v-if="field.rich" class="mt-1 text-xs text-faint">
                            Shown on the public site. Headings, bold and lists only — anything else is stripped when it is saved.
                        </p>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="saving"
                    class="rounded bg-accent px-5 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>

                <span v-if="saved" class="text-sm text-ok">Saved.</span>
                <span v-if="error" class="text-sm text-crit">{{ error }}</span>
            </div>
        </form>
    </div>
</template>
