import { createRouter, createWebHistory } from 'vue-router';

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
        { path: '/blog', name: 'blog', component: () => import('@/site/views/BlogView.vue') },
        { path: '/blog/:slug', name: 'article', component: () => import('@/site/views/ArticleView.vue') },
        { path: '/team', name: 'team', component: () => import('@/site/views/TeamView.vue') },
        { path: '/contact', name: 'contact', component: () => import('@/site/views/ContactView.vue') },
        { path: '/renew', name: 'renew', component: () => import('@/site/views/RenewView.vue') },

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

export default router;
