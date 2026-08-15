<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';

/**
 * A small formatting editor for package descriptions.
 *
 * Deliberately limited to headings, bold and lists. v1 used a full WYSIWYG and
 * its stored descriptions are thousands of characters of pasted inline styles
 * and font stacks - unreadable in a table, and unsafe to render. Offering only
 * what the content actually needs means that mess cannot be created again, and
 * the server strips anything that slips through anyway.
 */
const model = defineModel<string>({ default: '' });

const editor = ref<HTMLElement | null>(null);

/** Only sync inward when the value did not come from typing here. */
function syncFromModel() {
    if (editor.value && editor.value.innerHTML !== model.value) {
        editor.value.innerHTML = model.value || '';
    }
}

onMounted(syncFromModel);
watch(model, syncFromModel);

function onInput() {
    if (editor.value) model.value = editor.value.innerHTML;
}

function run(command: string, value?: string) {
    // execCommand is deprecated but still the only thing every browser
    // implements for contenteditable formatting, and the output is cleaned on
    // the server regardless of what it produces.
    document.execCommand(command, false, value);
    editor.value?.focus();
    onInput();
}

/** Paste as plain text, which is where the inline-style mess comes from. */
function onPaste(event: ClipboardEvent) {
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') ?? '';
    document.execCommand('insertText', false, text);
    onInput();
}

const tools = [
    { label: 'B', title: 'Bold', command: 'bold', class: 'font-bold' },
    { label: 'I', title: 'Italic', command: 'italic', class: 'italic' },
    { label: 'H', title: 'Heading', command: 'formatBlock', value: 'h3', class: 'font-bold' },
    { label: '¶', title: 'Paragraph', command: 'formatBlock', value: 'p', class: '' },
    { label: '• List', title: 'Bulleted list', command: 'insertUnorderedList', class: '' },
];
</script>

<template>
    <div class="rounded border border-line-strong bg-surface">
        <div class="flex flex-wrap gap-1 border-b border-line p-1.5">
            <button
                v-for="tool in tools"
                :key="tool.title"
                type="button"
                :title="tool.title"
                class="rounded px-2 py-1 text-xs text-body transition hover:bg-sunk hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                :class="tool.class"
                @click="run(tool.command, tool.value)"
            >
                {{ tool.label }}
            </button>
        </div>

        <div
            ref="editor"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            class="prose-sm min-h-32 max-h-80 overflow-y-auto px-3 py-2 text-sm text-ink focus:outline-none [&_h3]:mt-2 [&_h3]:font-semibold [&_li]:ms-4 [&_li]:list-disc [&_p]:mb-1 [&_ul]:mb-2"
            @input="onInput"
            @paste="onPaste"
        />
    </div>

    <p class="mt-1 text-xs text-faint">
        Headings, bold and bullet lists only. Pasted formatting is dropped, and anything else is
        removed when you save.
    </p>
</template>
