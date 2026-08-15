import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { api } from '@/shared/api/client';
import type { UserSettings } from '@/shared/types';

const DEFAULTS: UserSettings = {
    theme: 'system',
    menu_position: 'top',
    density: 'comfortable',
    sidebar: 'wide',
};

/**
 * Interface settings, held on the server against the user.
 *
 * The point of storing them server-side rather than in localStorage is that
 * they follow the person: sign in from a different machine and the navigation
 * is still where you put it.
 *
 * They are applied optimistically. Waiting for a round trip before redrawing
 * makes a theme toggle feel broken, and the worst case if the save fails is
 * that the choice does not survive a reload - which the error message says.
 */
export const useSettingsStore = defineStore('settings', () => {
    const settings = ref<UserSettings>({ ...DEFAULTS });
    const saveError = ref<string | null>(null);

    const theme = computed(() => settings.value.theme);
    const menuPosition = computed(() => settings.value.menu_position);
    const isCompact = computed(() => settings.value.density === 'compact');
    const sidebarCollapsed = computed(() => settings.value.sidebar === 'narrow');

    /**
     * Paint the choice onto the document.
     *
     * 'system' removes the attribute entirely rather than resolving it to light
     * or dark here, so the CSS media query stays in charge and the page follows
     * the operating system live - including when the user changes it while the
     * page is open.
     */
    function apply(): void {
        const root = document.documentElement;

        if (settings.value.theme === 'system') {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', settings.value.theme);
        }

        root.dataset.density = settings.value.density;
    }

    /** Adopt what came back with the session, without saving it again. */
    function hydrate(incoming: Partial<UserSettings> | undefined): void {
        settings.value = { ...DEFAULTS, ...(incoming ?? {}) };
        apply();
    }

    function reset(): void {
        settings.value = { ...DEFAULTS };
        apply();
    }

    async function set<K extends keyof UserSettings>(key: K, value: UserSettings[K]): Promise<void> {
        const previous = settings.value[key];

        settings.value = { ...settings.value, [key]: value };
        saveError.value = null;
        apply();

        try {
            const { data } = await api.patch('/me/settings', { [key]: value });
            // Take the server's answer as final: it drops anything it does not
            // recognise, so this is where an unsupported value gets corrected.
            settings.value = { ...DEFAULTS, ...data.data };
        } catch {
            // Put it back. Leaving the interface showing a choice that was not
            // saved is worse than the change not appearing to take.
            settings.value = { ...settings.value, [key]: previous };
            saveError.value = 'That preference could not be saved. It will go back to how it was when you reload.';
        } finally {
            apply();
        }
    }

    // Keep the document in step if anything writes to the ref directly.
    watch(settings, apply, { deep: true });

    return { settings, theme, menuPosition, isCompact, sidebarCollapsed, saveError, hydrate, reset, set, apply };
});
