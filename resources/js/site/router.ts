import { createRouter, createWebHistory } from 'vue-router';
import { useSiteFeatures, whenFeaturesKnown, type SiteFeatures } from '@/site/features';

/**
 * The public site.
 *
 * No auth store and no session check anywhere: nothing here asks who you are,
 * which is what keeps the marketing pages fast. Signing in leaves this bundle
 * entirely and loads the application.
 */
const router = createRouter({
    history: createWebHistory(),
    scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
    routes: [
        { path: '/', name: 'home', component: () => import('@/site/views/HomeView.vue') },
        { path: '/packages', name: 'packages', component: () => import('@/site/views/PackagesView.vue') },
        { path: '/questions', name: 'faq', component: () => import('@/site/views/FaqView.vue') },
        { path: '/subscribe', name: 'subscribe', component: () => import('@/site/views/SubscribeView.vue') },
        /*
         * Behind their own switches in Settings. The routes stay registered so
         * turning either back on needs no release, and the guard below sends
         * anybody with an old link or a bookmark to the home page rather than
         * to a screen whose API answers 404.
         */
        { path: '/blog', name: 'blog', component: () => import('@/site/views/BlogView.vue'), meta: { feature: 'blog' } },
        { path: '/blog/:slug', name: 'article', component: () => import('@/site/views/ArticleView.vue'), meta: { feature: 'blog' } },
        { path: '/team', name: 'team', component: () => import('@/site/views/TeamView.vue'), meta: { feature: 'team' } },
        { path: '/contact', name: 'contact', component: () => import('@/site/views/ContactView.vue') },
        { path: '/renew', name: 'renew', component: () => import('@/site/views/RenewView.vue') },
        // One of the four things the requirements document puts on the home page.
        { path: '/cloths', name: 'cloth-top-up', component: () => import('@/site/views/ClothTopUpView.vue'), meta: { feature: 'cloth_service' } },

        /*
         * Privacy, terms and refunds. One route for the three, because they are
         * the same page with different text - and a payment gateway will not
         * approve a business that cannot show them.
         */
        {
            path: '/policy/:page(privacy|terms|refunds)',
            name: 'policy',
            component: () => import('@/site/views/PolicyView.vue'),
        },

        /*
         * Sign in belongs to the other bundle, so this is a real page load
         * rather than a route: the browser has to fetch the application's
         * JavaScript, which this bundle does not contain.
         */
        {
            path: '/login',
            name: 'login',
            component: { render: () => null },
            beforeEnter: () => { window.location.href = '/login'; },
        },

        { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
    ],
});

/**
 * Keep switched-off pages out of reach.
 *
 * Awaited rather than read optimistically: a bookmark or a search result opens
 * straight onto the route, so the answer has to be in hand before deciding.
 * Everywhere else the flags are read as they arrive, because a menu that
 * flickers is worse than a menu that is briefly generous.
 */
router.beforeEach(async (to) => {
    const wanted = to.meta.feature as keyof SiteFeatures | undefined;

    if (!wanted) return true;

    const { features, known } = useSiteFeatures();

    if (!known.value) await whenFeaturesKnown();

    // Home rather than a "not found": these pages are not missing, the
    // business is not running them, and there is nothing to explain.
    return features.value[wanted] ? true : { name: 'home' };
});

export default router;
