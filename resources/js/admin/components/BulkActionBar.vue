<script setup lang="ts">
import { ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { describeError } from '@/shared/api/client';
import {
    bulkAssignCleaner, bulkSendMessage, bulkTemplates, cleanersForBranch,
} from '@/admin/shared/subscriptions.api';

/**
 * What to do with the ticked rows.
 *
 * Hidden until something is ticked: an always-visible bar of controls that do
 * nothing yet is just noise above the table.
 */
const props = defineProps<{ ids: string[]; count: number }>();
const emit = defineEmits<{ (e: 'done'): void }>();

const cleanerId = ref('');
const templateKey = ref('');
const busy = ref(false);
const notice = ref<string | null>(null);
const error = ref<string | null>(null);

const { data: cleaners } = useQuery({
    queryKey: ['bulk', 'cleaners'],
    queryFn: cleanersForBranch,
    staleTime: 5 * 60 * 1000,
});

const { data: templates } = useQuery({
    queryKey: ['bulk', 'templates'],
    queryFn: bulkTemplates,
    staleTime: 5 * 60 * 1000,
});

async function run(fn: () => Promise<{ message: string }>) {
    busy.value = true;
    notice.value = null;
    error.value = null;

    try {
        notice.value = (await fn()).message;
        emit('done');
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}

function assign() {
    return run(() => bulkAssignCleaner(props.ids, cleanerId.value || null));
}

function send() {
    const template = (templates.value ?? []).find((t) => t.key === templateKey.value);

    // The wording is shown before it goes, not after. A bulk send is not
    // undoable, and nobody should be sending words they have not read.
    const preview = template ? `\n\n"${template.preview}"` : '';

    if (!confirm(`Send "${template?.name}" to ${props.count} customer(s)?${preview}`)) {
        return Promise.resolve();
    }

    return run(() => bulkSendMessage(props.ids, templateKey.value));
}
</script>

<template>
    <div class="mb-4 rounded-lg border border-accent bg-accent-soft p-3">
        <div class="flex flex-wrap items-end gap-4">
            <p class="text-sm font-semibold text-accent-ink">
                {{ count }} selected
            </p>

            <label class="flex items-end gap-2">
                <span class="sr-only">Cleaner</span>
                <select
                    v-model="cleanerId"
                    class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option value="">No cleaner</option>
                    <option v-for="c in cleaners ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    @click="assign"
                >
                    Assign
                </button>
            </label>

            <label class="flex items-end gap-2">
                <span class="sr-only">Message</span>
                <select
                    v-model="templateKey"
                    class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option value="">Choose a message…</option>
                    <option v-for="t in templates ?? []" :key="t.key" :value="t.key">{{ t.name }}</option>
                </select>
                <button
                    type="button"
                    :disabled="busy || !templateKey"
                    class="rounded border border-accent px-3 py-1.5 text-sm font-medium text-accent-ink transition hover:bg-surface disabled:opacity-40"
                    @click="send"
                >
                    Send
                </button>
            </label>
        </div>

        <p v-if="notice" class="mt-2 rounded bg-ok-soft px-3 py-1.5 text-sm text-ok">{{ notice }}</p>
        <p v-if="error" class="mt-2 rounded bg-crit-soft px-3 py-1.5 text-sm text-crit">{{ error }}</p>

        <p class="mt-2 text-xs text-accent-ink">
            Each customer gets one message a day, whichever way it is sent.
        </p>
    </div>
</template>
