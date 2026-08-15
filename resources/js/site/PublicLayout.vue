<script setup lang="ts">
import { LOGO } from '@/shared/branding';
import { computed, ref } from 'vue';
import { RouterLink, RouterView } from 'vue-router';
import { useSiteSession } from '@/site/session';

/**
 * The public site: the marketing pages and the signup flow.
 *
 * Separate from AppLayout because it answers to nobody signed in - no branch
 * selector, no abilities, no session. The only thing it shares with the
 * application is the theme tokens, so both follow the viewer's light or dark
 * setting without a second palette to maintain.
 */
const open = ref(false);

/**
 * Somebody may already be signed in when they land here.
 *
 * The header showed "Sign in" regardless, and Subscribe took a customer who
 * already has a plan to a blank signup form - both of which read as though the
 * site had forgotten them.
 */
const { session } = useSiteSession();

/** The account link, or the signup call to action for a visitor. */
const primary = computed(() => (session.value
    ? { label: 'My account', href: '/login' }
    : null));

const links = [
    { name: 'Home', to: { name: 'home' } },
    { name: 'Packages', to: { name: 'packages' } },
    { name: 'Advice', to: { name: 'blog' } },
    { name: 'Team', to: { name: 'team' } },
    { name: 'Questions', to: { name: 'faq' } },
    { name: 'Contact', to: { name: 'contact' } },
    { name: 'Renew', to: { name: 'renew' } },
];
</script>

<template>
    <div class="flex min-h-screen flex-col bg-ground">
        <header class="sticky top-0 z-20 border-b border-line bg-surface/95 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
                <RouterLink :to="{ name: 'home' }" class="flex items-center">
                    <img :src="LOGO" alt="Eswachh" class="h-9 w-auto" />
                </RouterLink>

                <nav class="ms-auto hidden items-center gap-1 sm:flex">
                    <RouterLink
                        v-for="link in links"
                        :key="link.name"
                        :to="link.to"
                        class="rounded px-3 py-1.5 text-sm font-medium text-body transition hover:bg-sunk hover:text-ink"
                        active-class="text-accent-ink"
                    >
                        {{ link.name }}
                    </RouterLink>

                    <!--
                        A real page load rather than a route: their pages live
                        in the other bundle, which this one does not contain.
                    -->
                    <a
                        v-if="primary"
                        :href="primary.href"
                        class="ms-2 rounded bg-accent px-4 py-1.5 text-sm font-semibold text-on-accent transition hover:brightness-110"
                    >
                        {{ primary.label }}
                    </a>

                    <template v-else>
                        <RouterLink
                            :to="{ name: 'subscribe' }"
                            class="ms-2 rounded bg-accent px-4 py-1.5 text-sm font-semibold text-on-accent transition hover:brightness-110"
                        >
                            Subscribe
                        </RouterLink>

                        <RouterLink
                            :to="{ name: 'login' }"
                            class="rounded border border-line-strong px-3 py-1.5 text-sm font-medium text-body transition hover:bg-sunk"
                        >
                            Sign in
                        </RouterLink>
                    </template>
                </nav>

                <button
                    type="button"
                    class="ms-auto rounded border border-line-strong px-3 py-1.5 text-sm text-body sm:hidden"
                    :aria-expanded="open"
                    @click="open = !open"
                >
                    Menu
                </button>
            </div>

            <nav v-if="open" class="flex flex-col gap-1 border-t border-line px-4 py-2 sm:hidden">
                <RouterLink
                    v-for="link in links"
                    :key="link.name"
                    :to="link.to"
                    class="rounded px-3 py-2 text-sm font-medium text-body hover:bg-sunk"
                    @click="open = false"
                >
                    {{ link.name }}
                </RouterLink>

                <a v-if="primary" :href="primary.href" class="rounded px-3 py-2 text-sm font-medium text-accent hover:bg-sunk">
                    {{ primary.label }}
                </a>

                <template v-else>
                    <RouterLink :to="{ name: 'subscribe' }" class="rounded px-3 py-2 text-sm font-medium text-body hover:bg-sunk" @click="open = false">
                        Subscribe
                    </RouterLink>
                    <RouterLink :to="{ name: 'login' }" class="rounded px-3 py-2 text-sm font-medium text-body hover:bg-sunk" @click="open = false">
                        Sign in
                    </RouterLink>
                </template>
            </nav>
        </header>

        <main class="flex-1">
            <RouterView />
        </main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-6 text-sm text-muted">
                <span>Doorstep car cleaning, every day.</span>

                <!--
                    A gateway will not approve a business whose site cannot show
                    these, and a customer should not have to ask for them.
                -->
                <nav class="flex flex-wrap items-center gap-4">
                    <RouterLink :to="{ name: 'policy', params: { page: 'privacy' } }" class="hover:text-ink hover:underline">
                        Privacy
                    </RouterLink>
                    <RouterLink :to="{ name: 'policy', params: { page: 'terms' } }" class="hover:text-ink hover:underline">
                        Terms
                    </RouterLink>
                    <RouterLink :to="{ name: 'policy', params: { page: 'refunds' } }" class="hover:text-ink hover:underline">
                        Cancellation &amp; refunds
                    </RouterLink>
                </nav>

                <a v-if="primary" :href="primary.href" class="ms-auto text-accent hover:underline">{{ primary.label }}</a>
                <RouterLink v-else :to="{ name: 'login' }" class="ms-auto text-accent hover:underline">Sign in</RouterLink>
            </div>
        </footer>
    </div>
</template>
