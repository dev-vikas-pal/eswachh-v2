<script setup lang="ts">
import { computed, ref } from 'vue';
import { LOGO } from '@/shared/branding';
import { RouterLink, RouterView } from 'vue-router';
import { useSettingsStore } from '@/shared/stores/settings';
import SettingsMenu from '@/shared/SettingsMenu.vue';
import AccountMenu from '@/shared/AccountMenu.vue';

/**
 * The customer's shell.
 *
 * Deliberately not the office layout. There is no branch selector and no
 * sidebar of twelve screens: a customer has four pages and should see four.
 *
 * It does honour the menu position, though. Offering the choice in Appearance
 * and then ignoring it here - which is what it did - is worse than not offering
 * it: the setting saves, the radio moves, and nothing happens.
 */
const settings = useSettingsStore();

const drawerOpen = ref(false);

const sideways = computed(() => settings.menuPosition === 'left');

const LINKS = [
    { name: 'My plans', to: { name: 'portal-home' } },
    { name: 'Payments', to: { name: 'portal-payments' } },
    { name: 'Complaints', to: { name: 'portal-complaints' } },
    { name: 'My details', to: { name: 'portal-profile' } },
];
</script>

<template>
    <div class="min-h-screen bg-ground" :class="sideways ? 'md:flex' : ''">
        <!-- Down the side, on a wide screen. A drawer on a phone, where a
             permanent sidebar would leave no room for the page. -->
        <aside v-if="sideways" class="shrink-0 border-b border-line bg-surface md:w-52 md:border-b-0 md:border-r">
            <div class="flex items-center gap-2 px-4 py-3">
                <img :src="LOGO" alt="Eswachh" class="h-8 w-auto shrink-0" />

                <button
                    type="button"
                    class="ms-auto rounded border border-line-strong px-2 py-1 text-sm text-body md:hidden"
                    :aria-expanded="drawerOpen"
                    @click="drawerOpen = !drawerOpen"
                >
                    Menu
                </button>
            </div>

            <nav class="flex-col gap-0.5 px-2 pb-3 md:flex" :class="drawerOpen ? 'flex' : 'hidden'">
                <RouterLink
                    v-for="link in LINKS"
                    :key="link.name"
                    :to="link.to"
                    class="rounded px-3 py-2 text-sm text-body transition hover:bg-sunk hover:text-ink"
                    active-class="bg-accent-soft font-medium text-accent-ink"
                    @click="drawerOpen = false"
                >
                    {{ link.name }}
                </RouterLink>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="border-b border-line bg-surface">
                <div
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                    :class="sideways ? '' : 'mx-auto max-w-4xl'"
                >
                    <img v-if="!sideways" :src="LOGO" alt="Eswachh" class="h-8 w-auto shrink-0" />

                    <nav v-if="!sideways" class="flex flex-wrap items-center gap-1">
                        <RouterLink
                            v-for="link in LINKS"
                            :key="link.name"
                            :to="link.to"
                            class="rounded px-3 py-1.5 text-sm text-body transition hover:bg-sunk"
                            active-class="bg-sunk font-medium text-ink"
                        >
                            {{ link.name }}
                        </RouterLink>
                    </nav>

                    <div class="ms-auto flex items-center gap-2">
                        <SettingsMenu />
                        <AccountMenu />
                    </div>
                </div>
            </header>

            <main class="px-4 py-6" :class="sideways ? '' : 'mx-auto w-full max-w-4xl'">
                <RouterView />
            </main>
        </div>
    </div>
</template>
