<script setup lang="ts">
import { computed, ref } from 'vue';
import { api, describeError } from '@/shared/api/client';

/**
 * Choosing a picture, by uploading one or by typing a path.
 *
 * The path box stays because the images shipped with the site live under
 * /images and are referenced that way - somebody should be able to point at one
 * without re-uploading it. The picker is what makes the field usable for
 * everybody else: before this, a banner could only reference a file that had
 * already been put on the server by other means.
 */
const model = defineModel<string>({ default: '' });

const props = withDefaults(defineProps<{ folder?: string }>(), { folder: 'banners' });

const uploading = ref(false);
const problem = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);

const preview = computed(() => model.value?.trim() || '');

async function upload(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) return;

    uploading.value = true;
    problem.value = null;

    const body = new FormData();
    body.append('file', file);
    body.append('folder', props.folder);

    try {
        const { data } = await api.post('/uploads', body, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        // What comes back is a URL the browser can use, so it goes straight
        // into the field the front end reads.
        model.value = data.data.url;
    } catch (e) {
        problem.value = describeError(e).message;
    } finally {
        uploading.value = false;

        // Cleared so choosing the same file twice still fires a change event.
        if (input.value) input.value.value = '';
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <input
                v-model.trim="model"
                type="text"
                placeholder="/images/banners/monsoon.jpg"
                class="min-w-0 flex-1 rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            />

            <label
                class="shrink-0 cursor-pointer rounded border border-line-strong px-3 py-2 text-sm text-body transition hover:bg-sunk"
                :class="uploading ? 'pointer-events-none opacity-60' : ''"
            >
                {{ uploading ? 'Uploading…' : 'Upload' }}
                <input
                    ref="input"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/svg+xml"
                    class="hidden"
                    @change="upload"
                />
            </label>

            <button
                v-if="preview"
                type="button"
                class="shrink-0 rounded px-2 py-2 text-sm text-crit transition hover:bg-crit-soft"
                title="Remove the picture"
                @click="model = ''"
            >
                Remove
            </button>
        </div>

        <p v-if="problem" class="rounded bg-crit-soft px-3 py-1.5 text-xs text-crit">{{ problem }}</p>

        <!--
            Shown at the shape the banner uses, so what somebody sees here is
            what the home page will do with it rather than a square thumbnail
            that hides the cropping.
        -->
        <div v-if="preview" class="aspect-[16/9] w-full max-w-xs overflow-hidden rounded border border-line bg-sunk">
            <img :src="preview" alt="" class="h-full w-full object-contain" />
        </div>

        <p class="text-xs text-faint">
            JPG, PNG, WebP or SVG, up to 4 MB. Wide pictures work best.
        </p>
    </div>
</template>
