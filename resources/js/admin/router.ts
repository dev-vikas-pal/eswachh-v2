import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';

/**
 * The application, everything under /app plus the sign in page.
 *
 * Guards here are for the experience, not for security. They stop a signed out
 * person seeing an empty shell; they are not what keeps data safe. Every
 * request is authorised again on the server.
 */
const router = createRouter({
    history: createWebHistory(),
    scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
    routes: [
        { path: '/login', name: 'login', component: () => import('@/shared/LoginView.vue'), meta: { guest: true } },
        {
            path: '/app',
            component: () => import('@/admin/AppLayout.vue'),
            children: [
                { path: '', redirect: { name: 'dashboard' } },
                { path: 'dashboard', name: 'dashboard', component: () => import('@/admin/views/DashboardView.vue') },
                { path: 'subscriptions', name: 'subscriptions', component: () => import('@/admin/views/SubscriptionsView.vue'), meta: { ability: 'view.subscription' } },
                { path: 'customers', name: 'customers', component: () => import('@/admin/views/CustomersView.vue'), meta: { ability: 'view.customer' } },
                { path: 'payments', name: 'payments', component: () => import('@/admin/views/PaymentsView.vue'), meta: { ability: 'view.payment' } },
                { path: 'complaints', name: 'complaints', component: () => import('@/admin/views/ComplaintsView.vue'), meta: { ability: 'view.complaint' } },
                { path: 'round', name: 'round', component: () => import('@/admin/views/RoundView.vue'), meta: { ability: 'view.round' } },
                { path: 'coverage', name: 'coverage', component: () => import('@/admin/views/CoverageView.vue'), meta: { ability: 'view.attendance' } },
                { path: 'reminders', name: 'reminders', component: () => import('@/admin/views/RemindersView.vue'), meta: { ability: 'view.subscription' } },
                { path: 'reports', name: 'reports', component: () => import('@/admin/views/ReportsView.vue'), meta: { ability: 'view.report' } },
                { path: 'people', name: 'users', component: () => import('@/admin/views/UsersView.vue'), meta: { ability: 'view.staff' } },
                { path: 'masters', name: 'masters', component: () => import('@/admin/views/MastersView.vue'), meta: { ability: 'manage.master' } },
                { path: 'blog', name: 'blog-admin', component: () => import('@/admin/views/BlogAdminView.vue'), meta: { ability: 'manage.master' } },
                { path: 'backups', name: 'backups', component: () => import('@/admin/views/BackupsView.vue'), meta: { ability: 'manage.master' } },
                { path: 'settings', name: 'settings', component: () => import('@/admin/views/SiteSettingsView.vue'), meta: { ability: 'manage.master' } },
                { path: 'logs', name: 'logs', component: () => import('@/admin/views/LogsView.vue'), meta: { ability: 'manage.master' } },
                /*
                 * Roles are administrator only, and that is not an ability -
                 * see RoleController. The guard here uses the role directly for
                 * the same reason the server does.
                 */
                { path: 'roles', name: 'roles', component: () => import('@/admin/views/RolesView.vue'), meta: { superAdmin: true } },
            ],
        },

        /*
         * The customer's own pages.
         *
         * A separate branch of the tree rather than extra items in the office
         * navigation, because a customer is not a member of staff with fewer
         * boxes ticked - they see a different application.
         */
        {
            path: '/my',
            component: () => import('@/portal/PortalLayout.vue'),
            meta: { customer: true },
            children: [
                { path: '', redirect: { name: 'portal-home' } },
                { path: 'plans', name: 'portal-home', component: () => import('@/portal/views/MyPlansView.vue') },
                { path: 'payments', name: 'portal-payments', component: () => import('@/portal/views/MyPaymentsView.vue') },
                { path: 'complaints', name: 'portal-complaints', component: () => import('@/portal/views/MyComplaintsView.vue') },
                { path: 'details', name: 'portal-profile', component: () => import('@/portal/views/MyProfileView.vue') },
            ],
        },

        // Anything else in this bundle is a public page: leave for that site.
        { path: '/:pathMatch(.*)*', beforeEnter: () => { window.location.href = '/'; }, component: { render: () => null } },
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.ready) {
        await auth.loadSession();
    }

    // Where each kind of person belongs once they are signed in.
    const home = auth.isCustomer ? { name: 'portal-home' } : { name: 'dashboard' };

    if (to.meta.guest) {
        return auth.isSignedIn ? home : true;
    }

    if (!auth.isSignedIn) {
        return { name: 'login', query: { next: to.fullPath } };
    }

    /*
     * Customers hold view.subscription and view.payment - for their own plan -
     * so an ability check alone would let them walk into the office's lists.
     * Which application somebody belongs in is a question about who they are,
     * not about what they may do, so it is answered separately.
     */
    if (auth.isCustomer !== (to.meta.customer === true)) {
        return home;
    }

    if (to.meta.superAdmin && auth.user?.role.value !== 'super_admin') {
        return home;
    }

    if (to.meta.ability && !auth.can(to.meta.ability as string)) {
        return home;
    }

    return true;
});

export default router;
