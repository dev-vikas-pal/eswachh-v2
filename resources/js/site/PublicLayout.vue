<script setup lang="ts">
import { LOGO } from '@/shared/branding';
import { computed, ref } from 'vue';
import { RouterLink, RouterView } from 'vue-router';
import { useSiteSession } from '@/site/session';
import { useSiteFeatures } from '@/site/features';
import PaymentProgress from '@/shared/PaymentProgress.vue';

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
const { session, checked } = useSiteSession();

/** The account link, or the signup call to action for a visitor. */
const primary = computed(() => (session.value
    ? { label: 'My account', href: '/login' }
    : null));

/**
 * Renew and Subscribe are for visitors, so a signed-in customer is not shown
 * them.
 *
 * They do both from their own pages, where their address and phone are already
 * known and there is no code to wait for. The public forms would in fact turn
 * them away: signup refuses a mobile number it already holds, so an existing
 * customer was told their own number was taken.
 *
 * Waiting for the session check before showing anything is deliberate. The
 * alternative shows the buttons on first paint and pulls them away a moment
 * later, under the cursor of somebody already reaching for one.
 *
 * Staff are not customers, so they still see them. Hidden, not removed: the
 * pages, routes and forms are all untouched, and making these public to
 * everybody again is this one line.
 */
const showVisitorActions = computed(() => checked.value && session.value?.role !== 'customer');

/*
 * The pages anybody can read. Renew is not among them: it is one of the things
 * a visitor comes here to *do*, so it sits with Subscribe rather than being
 * lost in a row of eight links.
 */
const { features } = useSiteFeatures();

const links = computed(() => [
    { name: 'Home', to: { name: 'home' } },
    { name: 'Packages', to: { name: 'packages' } },
    // Both switchable in Settings. The routes stay registered and the server
    // refuses them, so this only decides whether to offer the link.
    ...(features.value.blog ? [{ name: 'Advice', to: { name: 'blog' } }] : []),
    ...(features.value.team ? [{ name: 'Team', to: { name: 'team' } }] : []),
    { name: 'Questions', to: { name: 'faq' } },
    { name: 'Contact', to: { name: 'contact' } },
]);

/*
 * Topping up cloths is not offered in the header at all.
 *
 * It belongs next to the plan it tops up - a customer thinking about cloths is
 * looking at their car, not at a navigation bar - so it lives on My plans. The
 * page and its route stay exactly as they are, reachable by anybody who has the
 * address, so putting it back is a one line change here.
 *
 * The whole service is also behind a business-wide switch; see
 * cloth_service_enabled in the settings.
 */
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
                        The two things a visitor comes here to do, kept
                        together: start a plan, or renew the one they have.
                        Hidden from a signed-in customer, who does both from
                        their own pages - see showVisitorActions.
                    -->
                    <template v-if="showVisitorActions">
                        <RouterLink
                            :to="{ name: 'renew' }"
                            class="ms-2 rounded border border-line-strong px-4 py-1.5 text-sm font-semibold text-body transition hover:bg-sunk"
                        >
                            Renew
                        </RouterLink>

                        <RouterLink
                            :to="{ name: 'subscribe' }"
                            class="rounded bg-accent px-4 py-1.5 text-sm font-semibold text-on-accent transition hover:brightness-110"
                        >
                            Subscribe
                        </RouterLink>
                    </template>

                    <!--
                        A real page load rather than a route: their pages live
                        in the other bundle, which this one does not contain.
                    -->
                    <a
                        v-if="primary"
                        :href="primary.href"
                        class="rounded border border-line-strong px-3 py-1.5 text-sm font-medium text-body transition hover:bg-sunk"
                    >
                        {{ primary.label }}
                    </a>

                    <RouterLink
                        v-else
                        :to="{ name: 'login' }"
                        class="rounded border border-line-strong px-3 py-1.5 text-sm font-medium text-body transition hover:bg-sunk"
                    >
                        Sign in
                    </RouterLink>
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

                <template v-if="showVisitorActions">
                    <RouterLink :to="{ name: 'renew' }" class="rounded px-3 py-2 text-sm font-medium text-body hover:bg-sunk" @click="open = false">
                        Renew
                    </RouterLink>

                    <RouterLink :to="{ name: 'subscribe' }" class="rounded px-3 py-2 text-sm font-medium text-body hover:bg-sunk" @click="open = false">
                        Subscribe
                    </RouterLink>
                </template>

                <a v-if="primary" :href="primary.href" class="rounded px-3 py-2 text-sm font-medium text-accent hover:bg-sunk">
                    {{ primary.label }}
                </a>

                <RouterLink v-else :to="{ name: 'login' }" class="rounded px-3 py-2 text-sm font-medium text-body hover:bg-sunk" @click="open = false">
                    Sign in
                </RouterLink>
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

        <!--
            Mounted once for the whole public site, so the signup, the renewal
            and the cloth top-up are all covered without any of them asking.
            It draws nothing until a payment is in flight.
        -->
        <PaymentProgress />
    </div>
</template>
