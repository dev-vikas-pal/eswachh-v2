<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useSettingsStore } from '@/shared/stores/settings';
import type { Density, MenuPosition, ThemeChoice } from '@/shared/types';

/**
 * Theme, navigation position and density.
 *
 * Every choice saves against the signed in person, so it follows them to
 * whatever machine they next sign in on rather than living in this browser.
 */
const settings = useSettingsStore();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const themes: Array<{ value: ThemeChoice; label: string; hint: string }> = [
    { value: 'system', label: 'Match my device', hint: 'Follows your system setting' },
    { value: 'light', label: 'Light', hint: '' },
    { value: 'dark', label: 'Dark', hint: '' },
];

const positions: Array<{ value: MenuPosition; label: string }> = [
    { value: 'top', label: 'Across the top' },
    { value: 'left', label: 'Down the side' },
];

const densities: Array<{ value: Density; label: string }> = [
    { value: 'comfortable', label: 'Comfortable' },
    { value: 'compact', label: 'Compact' },
];

function onDocumentPointer(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    // Escape closes it, which is what everyone tries first.
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('mousedown', onDocumentPointer);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointer);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex items-center gap-1.5 rounded border border-line-strong px-2.5 py-1.5 text-sm text-body transition hover:bg-sunk focus:outline-none focus:ring-2 focus:ring-accent"
            :aria-expanded="open"
            aria-haspopup="true"
            title="Appearance"
            @click="open = !open"
        >
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <circle cx="10" cy="10" r="3" />
                <path d="M10 1.5v2M10 16.5v2M18.5 10h-2M3.5 10h-2M16 4l-1.4 1.4M5.4 14.6 4 16M16 16l-1.4-1.4M5.4 5.4 4 4" stroke-linecap="round" />
            </svg>
            <span class="hidden sm:inline">Appearance</span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-30 mt-2 w-64 rounded-lg border border-line-strong bg-surface p-3 shadow-lg"
            role="menu"
        >
            <fieldset class="mb-3">
                <legend class="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted">Theme</legend>
                <div class="flex flex-col gap-0.5">
                    <label
                        v-for="option in themes"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-body transition hover:bg-sunk"
                    >
                        <input
                            type="radio"
                            name="theme"
                            class="accent-[var(--accent)]"
                            :value="option.value"
                            :checked="settings.theme === option.value"
                            @change="settings.set('theme', option.value)"
                        />
                        <span>
                            {{ option.label }}
                            <span v-if="option.hint" class="block text-xs text-faint">{{ option.hint }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="mb-3 border-t border-line pt-3">
                <legend class="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted">Menu</legend>
                <div class="flex flex-col gap-0.5">
                    <label
                        v-for="option in positions"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-body transition hover:bg-sunk"
                    >
                        <input
                            type="radio"
                            name="menu_position"
                            class="accent-[var(--accent)]"
                            :value="option.value"
                            :checked="settings.menuPosition === option.value"
                            @change="settings.set('menu_position', option.value)"
                        />
                        {{ option.label }}
                    </label>
                </div>
            </fieldset>

            <fieldset class="border-t border-line pt-3">
                <legend class="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted">Rows</legend>
                <div class="flex flex-col gap-0.5">
                    <label
                        v-for="option in densities"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-body transition hover:bg-sunk"
                    >
                        <input
                            type="radio"
                            name="density"
                            class="accent-[var(--accent)]"
                            :value="option.value"
                            :checked="settings.settings.density === option.value"
                            @change="settings.set('density', option.value)"
                        />
                        {{ option.label }}
                    </label>
                </div>
            </fieldset>

            <p class="mt-3 border-t border-line pt-2 text-xs text-faint">
                Saved to your account, so it follows you to any device.
            </p>

            <p v-if="settings.saveError" class="mt-2 rounded bg-crit-soft px-2 py-1.5 text-xs text-crit">
                {{ settings.saveError }}
            </p>
        </div>
    </div>
</template>
