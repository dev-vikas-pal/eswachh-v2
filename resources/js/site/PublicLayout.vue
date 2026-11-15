<script setup lang="ts">
import { LOGO } from '@/shared/branding';
import { computed, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { useSiteSession } from '@/site/session';
import { useSiteFeatures } from '@/site/features';
import PaymentProgress from '@/shared/PaymentProgress.vue';

/**
 * The public site: the marketing pages and the signup flow.
 *
 * Separate from AppLayout because it answers to nobody signed in - no branch
 * selector, no abilities, no session. It shares the token names with the
 * application and nothing else: the values behind them are the site's own,
 * set under html[data-site] in app.css, and it is light whatever the
 * visitor's device prefers.
 */
const open = ref(false);

// Following a link closes the phone menu, whichever link it was.
const route = useRoute();
watch(() => route.fullPath, () => { open.value = false; });

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

/**
 * The office's own details, for the footer.
 *
 * The same query the home and contact pages make, so it is answered from the
 * cache on every page after the first. A footer with a phone number in it
 * means nobody has to find the contact page to ask a question.
 */
const { data: content } = useQuery({
    queryKey: ['public-content'],
    queryFn: async () => (await api.get('/public/content')).data.data,
    staleTime: 5 * 60 * 1000,
});

const contact = computed(() => content.value?.contact ?? {});
const year = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-ground">
        <!-- For a keyboard, so the first Tab is not thirty links from the page. -->
        <a
            href="#content"
            class="sr-only z-50 rounded-full bg-ink px-4 py-2 text-sm font-semibold text-surface focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
        >
            Skip to the page
        </a>

        <header class="sticky top-0 z-30 border-b border-line/70 bg-ground/85 backdrop-blur-md">
            <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <RouterLink :to="{ name: 'home' }" class="flex shrink-0 items-center" aria-label="Eswachh, home">
                    <img :src="LOGO" alt="Eswachh" class="h-10 w-auto" />
                </RouterLink>

                <nav class="ms-6 hidden items-center gap-1 lg:flex" aria-label="Pages">
                    <RouterLink
                        v-for="link in links"
                        :key="link.name"
                        :to="link.to"
                        class="rounded-full px-3.5 py-2 text-sm font-medium text-body transition hover:bg-sunk hover:text-ink"
                        exact-active-class="bg-sunk text-ink"
                    >
                        {{ link.name }}
                    </RouterLink>
                </nav>

                <div class="ms-auto flex items-center gap-2">
                    <!--
                        A real page load rather than a route for the account
                        link: their pages live in the other bundle, which this
                        one does not contain.
                    -->
                    <a
                        v-if="primary"
                        :href="primary.href"
                        class="hidden rounded-full px-3.5 py-2 text-sm font-semibold text-ink transition hover:bg-sunk sm:inline-flex"
                    >
                        {{ primary.label }}
                    </a>

                    <RouterLink
                        v-else
                        :to="{ name: 'login' }"
                        class="hidden rounded-full px-3.5 py-2 text-sm font-semibold text-ink transition hover:bg-sunk sm:inline-flex"
                    >
                        Sign in
                    </RouterLink>

                    <!--
                        The two things a visitor comes here to do, kept
                        together: start a plan, or renew the one they have.
                        Hidden from a signed-in customer, who does both from
                        their own pages - see showVisitorActions.

                        Subscribe stays in the bar on a phone as well. It is
                        what the site is for, and it should not be the one
                        thing folded away behind a menu.
                    -->
                    <template v-if="showVisitorActions">
                        <RouterLink
                            :to="{ name: 'renew' }"
                            class="hidden rounded-full border border-line-strong bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:border-ink sm:inline-flex"
                        >
                            Renew
                        </RouterLink>

                        <RouterLink
                            :to="{ name: 'subscribe' }"
                            class="inline-flex rounded-full bg-accent px-4 py-2 text-sm font-semibold text-on-accent shadow-sm transition hover:bg-accent-ink"
                        >
                            Subscribe
                        </RouterLink>
                    </template>

                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-full border border-line-strong bg-surface text-ink transition hover:border-ink lg:hidden"
                        :aria-expanded="open"
                        aria-controls="site-menu"
                        :aria-label="open ? 'Close the menu' : 'Open the menu'"
                        @click="open = !open"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path v-if="open" d="m5 5 10 10M15 5 5 15" stroke-linecap="round" />
                            <path v-else d="M3.5 6h13M3.5 10h13M3.5 14h13" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>
            </div>

            <Transition name="menu">
                <nav
                    v-if="open"
                    id="site-menu"
                    class="border-t border-line bg-surface px-4 pb-5 pt-3 shadow-lg sm:px-6 lg:hidden"
                    aria-label="Pages"
                >
                    <div class="flex flex-col">
                        <RouterLink
                            v-for="link in links"
                            :key="link.name"
                            :to="link.to"
                            class="rounded-xl px-3 py-3 text-base font-medium text-ink transition hover:bg-sunk"
                            exact-active-class="bg-sunk"
                        >
                            {{ link.name }}
                        </RouterLink>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 border-t border-line pt-4">
                        <RouterLink
                            v-if="showVisitorActions"
                            :to="{ name: 'renew' }"
                            class="btn-quiet !py-3"
                        >
                            Renew a plan
                        </RouterLink>

                        <a v-if="primary" :href="primary.href" class="btn-quiet !py-3">{{ primary.label }}</a>
                        <RouterLink v-else :to="{ name: 'login' }" class="btn-quiet !py-3">Sign in</RouterLink>
                    </div>
                </nav>
            </Transition>
        </header>

        <main id="content" class="flex-1">
            <RouterView />
        </main>

        <footer class="border-t border-line bg-sunk">
            <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr_1.2fr] lg:px-8">
                <div>
                    <img :src="LOGO" alt="Eswachh" class="h-10 w-auto" />
                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-body">
                        Doorstep car cleaning, every morning, by the same cleaner. You see the price before
                        you pay and hear how each day went.
                    </p>
                </div>

                <nav aria-label="Explore">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-muted">Explore</h2>
                    <ul class="mt-4 flex flex-col gap-2.5 text-sm">
                        <li v-for="link in links" :key="link.name">
                            <RouterLink :to="link.to" class="text-body transition hover:text-accent-ink">{{ link.name }}</RouterLink>
                        </li>
                    </ul>
                </nav>

                <nav aria-label="Your plan">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-muted">Your plan</h2>
                    <ul class="mt-4 flex flex-col gap-2.5 text-sm">
                        <template v-if="showVisitorActions">
                            <li><RouterLink :to="{ name: 'subscribe' }" class="text-body transition hover:text-accent-ink">Start a plan</RouterLink></li>
                            <li><RouterLink :to="{ name: 'renew' }" class="text-body transition hover:text-accent-ink">Renew a plan</RouterLink></li>
                        </template>
                        <li>
                            <a v-if="primary" :href="primary.href" class="text-body transition hover:text-accent-ink">{{ primary.label }}</a>
                            <RouterLink v-else :to="{ name: 'login' }" class="text-body transition hover:text-accent-ink">Sign in</RouterLink>
                        </li>
                    </ul>
                </nav>

                <!-- Only what the office has actually filled in. -->
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-widest text-muted">Talk to us</h2>
                    <ul class="mt-4 flex flex-col gap-2.5 text-sm text-body">
                        <li v-if="contact.phone">
                            <a :href="'tel:' + contact.phone" class="text-base font-semibold tabular-nums text-ink transition hover:text-accent-ink">
                                {{ contact.phone }}
                            </a>
                        </li>
                        <li v-if="contact.hours">{{ contact.hours }}</li>
                        <li v-if="contact.email">
                            <a :href="'mailto:' + contact.email" class="transition hover:text-accent-ink">{{ contact.email }}</a>
                        </li>
                        <li>
                            <RouterLink :to="{ name: 'contact' }" class="font-medium text-accent-ink hover:underline">All contact details</RouterLink>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-line">
                <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-5 text-xs text-muted sm:px-6 lg:px-8">
                    <span>&copy; {{ year }} {{ contact.business_name || 'Eswachh' }}</span>

                    <!--
                        A gateway will not approve a business whose site cannot
                        show these, and a customer should not have to ask for
                        them.
                    -->
                    <nav class="ms-auto flex flex-wrap items-center gap-x-6 gap-y-2" aria-label="Policies">
                        <RouterLink :to="{ name: 'policy', params: { page: 'privacy' } }" class="transition hover:text-ink">
                            Privacy
                        </RouterLink>
                        <RouterLink :to="{ name: 'policy', params: { page: 'terms' } }" class="transition hover:text-ink">
                            Terms
                        </RouterLink>
                        <RouterLink :to="{ name: 'policy', params: { page: 'refunds' } }" class="transition hover:text-ink">
                            Cancellation &amp; refunds
                        </RouterLink>
                    </nav>
                </div>
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

<style scoped>
.menu-enter-active,
.menu-leave-active {
    transition: opacity 0.18s ease, transform 0.18s ease;
}

.menu-enter-from,
.menu-leave-to {
    opacity: 0;
    transform: translateY(-0.5rem);
}
</style>
