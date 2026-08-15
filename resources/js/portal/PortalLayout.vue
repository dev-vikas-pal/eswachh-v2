<script setup lang="ts">
import { LOGO } from '@/shared/branding';
import { RouterLink, RouterView } from 'vue-router';
import SettingsMenu from '@/shared/SettingsMenu.vue';
import AccountMenu from '@/shared/AccountMenu.vue';

/**
 * The customer's shell.
 *
 * Deliberately not the office layout. There is no branch selector, no sidebar
 * of twelve screens and no alerts bell: a customer has three pages and should
 * see three. Reusing the office chrome would show them controls that do
 * nothing for them and imply the rest of the business is a click away.
 */


const LINKS = [
    { name: 'My plans', to: { name: 'portal-home' } },
    { name: 'Payments', to: { name: 'portal-payments' } },
    { name: 'My details', to: { name: 'portal-profile' } },
];

</script>

<template>
    <div class="min-h-screen bg-ground">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex max-w-4xl flex-wrap items-center gap-3 px-4 py-3">
                <img :src="LOGO" alt="Eswachh" class="h-8 w-auto shrink-0" />

                <nav class="flex flex-wrap items-center gap-1">
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

        <main class="mx-auto max-w-4xl px-4 py-6">
            <RouterView />
        </main>
    </div>
</template>
